CC = xtensa-lx106-elf-gcc
ESPSDK = /Volumes/ESPtoolchain/esp-open-sdk/sdk
PORT = /dev/cu.usbserial-FTG4YM5W
CFLAGS = -I. -I$(ESPSDK)/include -mlongcalls -std=c99 -ffunction-sections -fdata-sections -Os -DICACHE_FLASH -Wno-implicit-function-declaration
LDLIBS = -nostdlib -L$(ESPSDK)/lib -Wl,--start-group -lmain -lnet80211 -lwpa -llwip -lpp -lphy -ldriver -Wl,--end-group -lgcc 
LDFLAGS = -T$(ESPSDK)/ld/eagle.app.v6.ld -Wl,--gc-sections -flto  -Wl,-Map,fw.map

firmware-0x00000.bin: firmware
	esptool.py elf2image $^

firmware: main.o httpd.o spi.o
	$(CC) -o firmware main.o httpd.o spi.o $(LDFLAGS) $(LDLIBS)

%.o:	%.c
	$(CC) $(CFLAGS) -c -o $@ $<

flash: firmware-0x00000.bin
	esptool.py --port $(PORT) --baud 460800 write_flash 0 firmware-0x00000.bin 0x40000 firmware-0x40000.bin

clean:
	rm -f firmware main.o uart.o firmware-0x00000.bin firmware-0x400000.bin


