E-ink wifi display
==================

This project contains the software bits for a e-ink display David has created, and I've modified

You might find some info at his website: https://davidgf.net/page/41/e-ink-wifi-display

Contents
--------

esp8266_firmware: firmware sources for the ESP, built using the xtensa toolchain and the IoT SDK (used v1.5)

media: Some pictures used by the screen

server: PHP hell! Contains some cool JS editor David wrote that allows users to design screens, and where I added some widgets I needed

stm32_application: STM32F firmware that actually drives the screen (was builtin in the board!)

stm32f10x: ST includes and libs used to build the stm32 firmware

How to build
------------

You will need to build the FW images with their corresponding toolchaings and flash them.
If you're using MacOSX or Linux, you'll be interested in:
- https://github.com/texane/stlink for the method to upload the freshly built STM32 firmware to your board (you need a ST-LINKv2 programmer, easy to find on Ebay for 5$)
- https://launchpad.net/gcc-arm-embedded for an already compiled cross compiler (used to build the STM32 firmware)
- The Expressif SDK (If you need a prebuild disk image for MacOSX, I'll upload mine)

To reuse the display driver for another board, just redefine the macros to point to your GPIOs and you are good to go!

The server should be copied to a PHP enabled server, create a config.php and fill it. Also create a screens/ dir and chmod it to be world readable/writtable.

Schematics
----------

Unlike David's method, I've tried to use SPI line and they proved too much unreliable to my taste (and it's hard to solder them if you don't have the right tools).
Also, here's how I connected mine (much much easier):
- Connected RX on the STM32 big white connector (DIN pin 3) on the left to the ESP8266's pin GPIO15 (I'm using the trick to swap the UART0TX output to MTDO)
- Connected GND from the ESP8266 to the programming pin on the STM32 (see David's website for a description of this programming pins, basically it's the third)
- Connected 3.3V from the ESP8266 to the programming pin on the STM32 (it's the first pin)
- Connected RST on the STM32 big white connector (RST pin 1) on the left to the ESP8266's pin GPIO12.

There is no battery in my design, you need to plug a microUSB power adapter (or a power bank) to the ESP8266 board to power the whole system. 
I'll see if in the future I'll put back a power gating transistor to power on the STM32 from the ESP8266, but currently, it works and I don't really fix what's not broken yet.

You can plug a 3.3V UART to USB on the UART1 TX line on the ESP8266 if you want to see debug messages from the ESP (115200 bauds 8N1), or on the DOUT on the STM32 (460800 bauds 8N1)
See below for changes...


Changes
-------
I've changed the code for both CPU to use UART lines instead of SPI.
I've added support for header line parsing in the HTTP response so the server can decide how often the ESP8266 wakes up (or how long it'll sleep).
I've added support for storing the server's and WIFI information in the ESP's flash (so it survives power drop). If you need to reset this parameters, boot with a wire between GPIO4 and GND on the ESP8266.
I've changed the code so the ESP8266 actually sleeps (David's code had a bug with it and it failed entering sleep and restarted due to watchdog instead every 30s or so). Sleep time is 10mn by default.
The server's weather widgets have been updated to support multi-languages.
I've added a traffic information widget (you need to register for BingMap api to use it, it's free) and made the required code to transform a 24bits per pixel to 4bits per pixel picture.
Soon, there'll also be a Caldav's next event remainder widget...
I've made a case for it if you have access to a 3D printer: https://cad.onshape.com/documents/366875cd2b4c0add4211b015/w/173325f8378b7a2f71e5b032/e/1de13be82e26fd3fe2a156ce
You can hang it on the wall, with the 2 part of the assembly if you need it.
[!Some pictures][http://imgur.com/MejbGxD.png]
 
 

GDE043A2
--------

This thing uses the Good Diplay device codenamed GDE043A2, you may find stuff googling the web. The main issue though is that there is little support and the datasheet doesn't explain a thing, check gde043a2.c to see how the driver actually works. It seems GDE060BA works all much the same (they seem to be the same device with slightly different pinouts and screen sizes, but same resolution and probably same driver).

