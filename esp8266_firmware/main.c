#include "ets_sys.h"
#include "osapi.h"
#include "gpio.h"
#include "os_type.h"
#include "ip_addr.h"
#include "espconn.h"
#include "user_interface.h"
#include "user_config.h"
//#include "uart_register.h"
#include "spi.h"
#include "../driver_lib/include/driver/uart.h"
#include "httpd.h"

// Global settings, stored in all memories
struct GlobalSettings {
	char      conf_essid[32] __attribute__ ((aligned (4)));  // Wireless ESSID
	char      conf_passw[32] __attribute__ ((aligned (4)));  // WPA/WEP password
	char      conf_hostn[32] __attribute__ ((aligned (4)));  // Server hostname
	char      conf_path [64] __attribute__ ((aligned (4)));  // Server path to the servlet URI
	uint32_t  conf_port;                                     // Server port for the service
	uint32_t  checksum;
} global_settings;

struct espconn host_conn;
ip_addr_t host_ip;
esp_tcp host_tcp;

int sleep_time_ms = 10 * 60 * 1000;

static volatile os_timer_t sleep_timer;
void ICACHE_FLASH_ATTR put_back_to_sleep() {
	os_printf("Goto sleep\n");
	system_deep_sleep_set_option(1); // Full wakeup!
	system_deep_sleep(sleep_time_ms*1000);
	os_printf("Gone to sleep\n");
}

void ICACHE_FLASH_ATTR power_gate_screen(int enable) {
	// Set GPIO2 to output mode
	PIN_FUNC_SELECT(PERIPHS_IO_MUX_MTDI_U, FUNC_GPIO12);

	// Set GPIO2 high!
	if (enable)
		gpio_output_set(BIT12, 0, BIT12, 0);
	else
		gpio_output_set(0, BIT12, BIT12, 0);
}

// Check if the GPIO is grounded (if yes, we clear the current the configuration)
int ICACHE_FLASH_ATTR clearbutton_pressed() {
	// Set GPIO4 as input mode with pull up
	PIN_FUNC_SELECT(PERIPHS_IO_MUX_GPIO4_U, FUNC_GPIO4);
	PIN_PULLUP_EN(PERIPHS_IO_MUX_GPIO4_U);
	// Set as input now
	gpio_output_set(0, 0, 0, BIT4);

	return GPIO_INPUT_GET(GPIO_ID_PIN(4));
}

enum ScreenCommand {
	DispApSetup = 0x00,
	DispLostConnection = 0x01,
	DispLowBat = 0x02,
	DispSleepMode = 0x03,
	DispDHCPErr = 0x04,
	DispDNSErr = 0x05,
	DispConnectError = 0x06,
	DispImageError = 0x07
};

// Keep the WDT happy! :D
void ICACHE_FLASH_ATTR delay_ms(int ms) {
	while (ms > 0) {
		system_soft_wdt_feed();
		os_delay_us(10000);
		ms -= 10;
	}
}

#ifdef UseSPI
#define SendByte(X) 	spi_tx8(HSPI, X)
#else
#define SendByte(X)	uart_tx_one_char(0, X)
#endif

void ICACHE_FLASH_ATTR screen_update(unsigned char screen_id) {
	// Update the screen!
	os_printf("SCREENUPDATE %d\n");

	// Flush the FIFO, to make sure no characters are in the buffer
	UART_ResetFifo(0);
	delay_ms(10);

	// Turn off the daughter board
	power_gate_screen(1);

	// Wait around half a second for it to boot properly
	delay_ms(150);

	// Send the command through the UART
	SendByte(screen_id);

	// Wait around 4s for it to display the image properly
	delay_ms(3500);

	// Now turn screen off
	power_gate_screen(0);
}

int ICACHE_FLASH_ATTR parse_answer( char * pdata, unsigned short len) {
	if (len < 13 || memcmp(pdata, "HTTP/1.", 7) != 0) {
		return 404; // No answer line found
	}
	return cheap_atoi(pdata+9);
}

// Advance the pointer and length while the find character is not found (if negate_test is 0), or until it's found (if negate_test is 1)
int ICACHE_FLASH_ATTR glob_until( char find, char ** ppdata, unsigned short * len, int negate_test) {
	while (*len && (**ppdata != find) ^ negate_test) {
		--(*len);
                ++(*ppdata);
	}
	return *len != 0;
}

int ICACHE_FLASH_ATTR parse_header( char ** ppdata, unsigned short * len, char ** header, char ** value) {
	*header = *ppdata;
	if (**ppdata == '\r' && *len && *(*ppdata+1) == '\n') {
		(*len) -= 2;
		(*ppdata) += 2;
		return 0; // End of headers found
	}
	glob_until(':', ppdata, len, 0);
	if (!*len)
		return -1; // Unexpected end of stream

	**ppdata = '\0'; // Make it zero terminated so we can parse it
	--(*len);
	++(*ppdata);

	// Strip whitespace too
	glob_until(' ', ppdata, len, 1);
	*value = *ppdata;

	if (!glob_until('\n', ppdata, len, 0))
		return -1; // Unexpected end of stream

	*(*ppdata - 1) = '\0'; // Make it zero terminated too
	--(*len);
	++(*ppdata);
	return 1; // Header found
}

uint32_t sentBytes = 0;
void ICACHE_FLASH_ATTR data_received( void *arg, char *pdata, unsigned short len) {
	struct espconn *conn = arg;
	char *header, *value;
	int ret = 0, content_len = len;

	static char screen_on = 0;
	if (!screen_on) {
		screen_on = 1;

		// Prepare screen! Tell image is coming!
		power_gate_screen(1);
		delay_ms(50);
	}

	os_printf("Data received: %d bytes\n", len);
	static int topline_received = 0;
	if (!topline_received) {
		// Scan through the data to strip headers!
		if ((ret = parse_answer(pdata, len)) > 302) {
			os_printf("Bad answer from server: %d from %s\n", ret, pdata);
			SendByte(DispConnectError);
			put_back_to_sleep();
			return;
		}
		topline_received = 1;
		// Skip answer line (we don't care about the result here)
		glob_until('\n', &pdata, &len, 0);
		pdata++; len--;
	}

	static int header_received = 0;
	if (!header_received) {
		// Then parse all header lines, and act accordingly
		while ((ret = parse_header(&pdata, &len, &header, &value)) > 0) {
#define STREQ(X,Y) memcmp(X, Y, sizeof(Y)) == 0
			if (STREQ(header, "Sleep-Duration-Ms")) {
				sleep_time_ms = cheap_atoi(value);
			}
			else if (STREQ(header, "Content-Length")) {
				content_len = cheap_atoi(value);
			}
	#undef STREQ
			os_printf("Got header %s with value %s\n", header, value);
		}
		if (ret == 0) {
			header_received = 1;
			// Tell image is coming!
			SendByte(0x40);
		}
	}
	
	if (header_received) {
//		pp_soft_wdt_stop();
//		os_printf("Sending with len %d and first byte %02x\n", len, *pdata);
//		os_printf("Current sleep time is %d\n", sleep_time_ms);


		while (len--) {
			SendByte(*pdata++);
			sentBytes++;
			if (!(len & 4095))
				system_soft_wdt_feed();
		}
//		pp_soft_wdt_restart();
	}
}

void nullwr(char c) {}

void ICACHE_FLASH_ATTR tcp_connected(void *arg)
{
	struct espconn *conn = arg;
	
	os_printf( "%s\n", __FUNCTION__ );
	espconn_regist_recvcb(conn, data_received);

	char buffer[256];
	os_sprintf(buffer, "GET %s HTTP/1.0\r\nHost: %s\r\nConnection: close\r\n\r\n", global_settings.conf_path, global_settings.conf_hostn);
	
	espconn_send(conn, buffer, os_strlen(buffer));
}

void ICACHE_FLASH_ATTR tcp_disconnected(void *arg) {
	struct espconn *conn = arg;
	
	// Disconnect board after 3.5 seconds
	delay_ms(3500);
	power_gate_screen(0);

	os_printf( "%s %d\n", __FUNCTION__, sentBytes );
	wifi_station_disconnect();

	put_back_to_sleep();
}

void ICACHE_FLASH_ATTR tcp_error (void *arg, sint8 err) {
	// TCP error, just show a message and back to sleep!
	screen_update(DispConnectError);
	put_back_to_sleep();
}

void ICACHE_FLASH_ATTR dns_done_cb( const char *name, ip_addr_t *ipaddr, void *arg ) {
	struct espconn *conn = arg;
	
	os_printf("%s\n", __FUNCTION__);
	
	if (ipaddr == NULL) {
		os_printf("DNS lookup failed\n");
		wifi_station_disconnect();

		// Show the DNS error screen
		screen_update(DispDNSErr);

		// Go back to sleep!
		put_back_to_sleep();
	}
	else {
		os_printf("Connecting...\n" );
		
		conn->type = ESPCONN_TCP;
		conn->state = ESPCONN_NONE;
		conn->proto.tcp = &host_tcp;
		espconn_regist_time(&host_tcp, 30, 0);
		conn->proto.tcp->local_port = espconn_port();
		conn->proto.tcp->remote_port = global_settings.conf_port;
		conn->proto.tcp->remote_port = global_settings.conf_port;
		os_memcpy( conn->proto.tcp->remote_ip, &ipaddr->addr, 4 );

		espconn_regist_connectcb(conn, tcp_connected);
		espconn_regist_disconcb (conn, tcp_disconnected);
		espconn_regist_reconcb  (conn, tcp_error);
		
		espconn_connect(conn);
	}
}

void ICACHE_FLASH_ATTR wifi_callback( System_Event_t *evt ) {
	os_printf("%s: %d\n", __FUNCTION__, evt->event);
	
	switch (evt->event) {
		case EVENT_STAMODE_CONNECTED: {
			os_printf("connect to ssid %s, channel %d\n",
						evt->event_info.connected.ssid,
						evt->event_info.connected.channel);
			break;
		}

		case EVENT_STAMODE_DISCONNECTED: {
			os_printf("disconnect from ssid %s, reason %d\n",
						evt->event_info.disconnected.ssid,
						evt->event_info.disconnected.reason);		   
			break;
		}

		case EVENT_STAMODE_GOT_IP: {
			os_printf("ip:" IPSTR ",mask:" IPSTR ",gw:" IPSTR,
						IP2STR(&evt->event_info.got_ip.ip),
						IP2STR(&evt->event_info.got_ip.mask),
						IP2STR(&evt->event_info.got_ip.gw));
			os_printf("\n");
			
			espconn_gethostbyname (&host_conn, global_settings.conf_hostn, &host_ip, dns_done_cb);
			break;
		}

		case EVENT_STAMODE_DHCP_TIMEOUT: {
			// DHCP failed! Show error and retry later
			screen_update(DispDHCPErr);
			put_back_to_sleep();
			break;
		}
	}
}

int ICACHE_FLASH_ATTR check_settings_checksum(uint32_t * checksum) {
	uint32_t * d = (uint32_t*)&global_settings;
	uint32_t sum = 0x2BADFACE; // Must not be zero
	for(int i = 0; i < (sizeof(global_settings) - sizeof(uint32_t))/sizeof(uint32_t); i++) {
		sum += d[i];
	}
	if (checksum) *checksum = sum;
	return sum == global_settings.checksum;
}


int ICACHE_FLASH_ATTR recover_settings() {
	// Try to recover data from the RTC memory first
	system_rtc_mem_read(64, &global_settings, sizeof(global_settings));
	if (check_settings_checksum(0))
		return 1;

	// Then read flash
	spi_flash_read(0x3C000, (uint32 *)&global_settings, sizeof(global_settings));
	if (check_settings_checksum(0))
		return 1;

	// Then try shadow flash too
	spi_flash_read(0x3D000, (uint32 *)&global_settings, sizeof(global_settings));
	if (check_settings_checksum(0))
		return 1;

	// Dump the RTC memory here
	uint32_t w = 0;
	os_printf("Failed to restore settings, RTC dump:\n");
	for (int i = 64; i < 192; i++) {
		if ((i % 16) == 0) os_printf("\n%04x ", i);
		system_rtc_mem_read(i,  &w, 4);
		os_printf("%08x ", w);
	}
	os_printf("\n");
	return 0; // Done!
}

void ICACHE_FLASH_ATTR store_settings() {
	// First write the config and then the magic
	os_printf("Store %s %s\n", global_settings.conf_essid, global_settings.conf_passw);
	// First in flash
	spi_flash_erase_sector(0x3C);
	spi_flash_write(0x3C000, (uint32_t*)&global_settings, sizeof(global_settings));
	spi_flash_erase_sector(0x3D);
	spi_flash_write(0x3D000, (uint32_t*)&global_settings, sizeof(global_settings));
	// Then in RTC mem
	system_rtc_mem_write(64, &global_settings, sizeof(global_settings));
}


void ICACHE_FLASH_ATTR processing_timeout(void * arg) {
	// Shut down the thing! for now...
	int n = (int)arg;
	switch (n) {
	case 0:
		// Refresh timeout, normal wakeup
		power_gate_screen(0);
		put_back_to_sleep();
		break;
	case 1:
		// The user to reset the board after this manually
		screen_update(DispSleepMode);
		break;
	case 3:
		// This is a quick reboot
		system_deep_sleep_set_option(1); // Full wakeup!
		system_deep_sleep(1000);  // 1ms
		break;
	}

}


// Web server for config setting
static const char *index_200 = "HTTP/1.1 200 OK\r\nContent-Type: text/html\r\nConnection: close\r\n\r\n"
	"<html><head><title>E-ink Wifi Setup</title></head><body>"
	"<form action=\"push\" method=POST>"
	"<center><table>"
	"<tr><td>ESSID:</td><td><input type=\"text\" name=\"essid\"></td></tr>"
	"<tr><td>Password:</td><td><input type=\"text\" name=\"pass\"></td></tr>"
	"<tr><td>Server:</td><td><input type=\"text\" name=\"host\"></td></tr>"
	"<tr><td>Port:</td><td><input type=\"text\" name=\"port\"></td></tr>"
	"<tr><td>URL:</td><td><input type=\"text\" name=\"path\"></td></tr>"
	"</table><input type=\"submit\"></center>"
	"</form>"
	"</body></html>";

static const char *push_200 = "HTTP/1.1 200 OK\r\nContent-Type: text/html\r\nConnection: close\r\n\r\n"
	"<html><head><title>E-ink Wifi Setup</title></head><body>"
	"<center>"
	"Config saved! Rebooting ... <br/> You can safely disconnect from this wifi access point"
	"</center>"
	"</body></html>";

static volatile os_timer_t reboot_timer;
unsigned ICACHE_FLASH_ATTR get_index(char * buffer, const char * body) {
	// Just return a form!
	strcpy(buffer, index_200);
	return strlen(index_200);
}
unsigned ICACHE_FLASH_ATTR push_settings(char * buffer, const char * body) {
	os_printf("TOPARSE %s\n", body);
	// Parse body variables
	parse_form_s(body, global_settings.conf_essid, "essid");
	parse_form_s(body, global_settings.conf_passw, "pass");
	parse_form_s(body, global_settings.conf_hostn, "host");
	parse_form_s(body, global_settings.conf_path,  "path");
	global_settings.conf_port = parse_form_d(body, "port");
#define Z(X) global_settings.X[sizeof(global_settings.X) - 1] = '\0'
	Z(conf_essid); Z(conf_passw); Z(conf_hostn); Z(conf_path);
#undef Z

	os_printf("Parsed %s %s %s\n", global_settings.conf_essid, global_settings.conf_passw, global_settings.conf_hostn);
	os_printf("Parsed path: %s\n", global_settings.conf_path);

	// Compute checksum
	check_settings_checksum(&global_settings.checksum);
	// Update settings
	store_settings();

	// Schedule a reboot in 5 seconds
	os_timer_setfn(&reboot_timer, processing_timeout, 3);
	os_timer_arm(&reboot_timer, 5000, 0);
	
	strcpy(buffer, push_200);
	return strlen(push_200);
}

t_url_desc urls[] = {
	{ "/",     get_index },
	{ "/push", push_settings },
	{ NULL, NULL },
};


void ICACHE_FLASH_ATTR start_web_server() {
	// Set SoftAP mode
	if (wifi_get_opmode() != 2)
		wifi_set_opmode(2);

	// Setup AP mode
	struct softap_config config;
	strcpy(config.ssid, "EINKWIFI");
	config.password[0] = 0;
	config.channel = 1;
	config.ssid_hidden = 0;
	config.authmode = AUTH_OPEN;
	config.ssid_len = 0;
	config.beacon_interval = 100;
	config.max_connection = 4;

	wifi_softap_set_config(&config);// Set ESP8266 softap config .

	httpd_start(80, urls);
}

#define FUNC_U0CTS    4
#define FUNC_U0RTS    4

void ICACHE_FLASH_ATTR prepare_uart( void ) {
 
	PIN_FUNC_SELECT(PERIPHS_IO_MUX_MTCK_U, FUNC_U0CTS);//CONFIG MTCK PIN FUNC TO U0CTS
	PIN_FUNC_SELECT(PERIPHS_IO_MUX_MTDO_U, FUNC_U0RTS);//CONFIG MTDO PIN FUNC TO U0RTS
	SET_PERI_REG_MASK(0x3ff00028 , BIT2);//SWAP PIN : U0TXD<==>U0RTS(MTDO) , U0RXD<==>U0CTS(MTCK)
}
extern UartDevice    UartDev;
extern void ICACHE_FLASH_ATTR uart1_write_char(char c)
{
	uart_tx_one_char(1, c);
}

void ICACHE_FLASH_ATTR user_init( void ) {
	// Init GPIO stuff
	gpio_init();

	// 
#ifdef UseSPI
	// SPI setup! Ready to go!
	spi_init(HSPI);
	spi_mode(HSPI, 1, 1);
	spi_init_gpio(HSPI, 0);
	spi_clock(HSPI, 6, 29);  // Div by 174 to get 406kbps
//	spi_clock(HSPI, 1, 20);  // Div by 20 to get 4MBps for now
#else
	UartDev.exist_parity = 0;
        uart_init(460800, 115200);

        os_install_putc1((void *)uart1_write_char);
	prepare_uart(); // From now on, it'll output UART0 TX on the MTDO pin aka GPIO15 / HSPI_CS
/*
	// Set UART0 to high speed!
	uart_div_modify( 0, UART_CLK_FREQ / ( 74880 ) ); // More decent than 460800 ) );  // 921600
	// Set UART1 to high speed!
	uart_div_modify( 1, UART_CLK_FREQ / ( 115200 ) ); // More decent than 460800 ) );  // 921600
/**/

	os_printf("Booting...\n");
	os_memset(&global_settings, 0, sizeof(global_settings));

	// Use GPIO2 as UART0 output as well :)
//	PIN_FUNC_SELECT(PERIPHS_IO_MUX_GPIO2_U, FUNC_U1TXD_BK);

#endif

	// Test UART0 output at 460800, you should monitor line GPIO15 with your UART adapter and see this ONLY on the output
/*
        SendByte('H');
        SendByte('e');
        SendByte('l');
        SendByte('l');
        SendByte('o');
        SendByte('\n');
*/
/*

	delay_ms(1000);
        uint8_t checksum = 0;
	SendByte(0x40);
	for (uint16_t i = 0; i < 60*1024; i++) {
		checksum += (i & 0xFF);
		SendByte((i & 0xFF));
		if (!(i & 4095))
		    system_soft_wdt_feed();
	}
	os_printf("Sent all 61440 bytes with checksum: %u\n", checksum);
		os_timer_setfn(&sleep_timer, processing_timeout, (void*)1);
		os_timer_arm(&sleep_timer, 5*60*1000, 0);
	return;	
/**/

	// First of all read the RTC memory and check whether data is valid.
	os_printf("Clear config setting: %d (use GPIO4 to gnd to clear)\n", clearbutton_pressed());
	if (clearbutton_pressed() == 0)
		store_settings();  // This will nuke the saved settings

	if (recover_settings()) {
		// We got some settings, now go and connect
		static struct station_config config;
		wifi_station_set_hostname("einkdisp");
		wifi_set_opmode_current(STATION_MODE);

		os_printf("Info %s, %s, %s, %d\n", global_settings.conf_passw, global_settings.conf_essid, global_settings.conf_hostn, global_settings.conf_port);
	
		config.bssid_set = 0;
		os_memcpy(&config.ssid,     global_settings.conf_essid, sizeof(global_settings.conf_essid));
		os_memcpy(&config.password, global_settings.conf_passw, sizeof(global_settings.conf_passw));
		wifi_station_set_config(&config);

		// Connect to the server, get some stuff and process it!
		wifi_set_event_handler_cb(wifi_callback);

		// To prevent battery going nuts, add a failback timer of 30 seconds
		// which should be more than enough for the whole process
		os_timer_setfn(&sleep_timer, processing_timeout, (void*)0);
		os_timer_arm(&sleep_timer, 30000, 0);
	}
	else {
		os_printf("Starting web server\n");

		// Start web server and wait for connections!
		start_web_server();

		// Display the AP setup screen
		screen_update(DispApSetup);

		// To prevent battery drain, schedule sleep in 5 minutes
		// Present a "sleeping" screen after that :)
		os_timer_setfn(&sleep_timer, processing_timeout, (void*)1);
		os_timer_arm(&sleep_timer, 5*60*1000, 0);
	}
}


