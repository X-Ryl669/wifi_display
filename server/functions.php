<?php

require_once("config.php");
require_once("auth.php");
require_once("provider.php");
require_once("weather.php");
require_once("currency.php");
require_once("stock.php");
require_once("btc.php");
require_once("forecast.php");
require_once("roadtraffic.php");
require_once("calevents.php");

function array2js($a) {
	if (is_array($a)) {
		$ret = "";
		foreach ($a as $key => $value)
			$ret .= array2js($key).": ".array2js($value).", ";
		return "{".$ret."}";
	}
	else if (is_string($a))
		return "'$a'";

	return $a;
}

// Because both Inkscape and RSVG renderer do not support textLength attribute for text element in SVG, we need to compute the text length by ourselves
function calculateTextBox($text, $font, $fontSizePx, $fontAngle) {
    $fontPath =  dirname(__FILE__).'/fonts/'.strtolower($font).".ttf";
    $rect = imagettfbbox(floor($fontSizePx / 1.33), $fontAngle, $fontPath, $text);
    $minX = min(array($rect[0],$rect[2],$rect[4],$rect[6]));
    $maxX = max(array($rect[0],$rect[2],$rect[4],$rect[6]));
    $minY = min(array($rect[1],$rect[3],$rect[5],$rect[7]));
    $maxY = max(array($rect[1],$rect[3],$rect[5],$rect[7]));

    return array(
     "left"   => abs($minX) - 1,
     "top"    => abs($minY) - 1,
     "width"  => $maxX - $minX,
     "height" => $maxY - $minY,
     "box"    => $rect);
}

class Providers {
	// Returns list of providers
	static function getProvidersList() {
		$providers = array();
		foreach(get_declared_classes() as $klass) {
			$reflect = new ReflectionClass($klass);
			if ($reflect->implementsInterface('ServiceProvider')) {
				$prop = $reflect->getStaticProperties();
				$wname = $prop["widgetName"];
				$providers[$wname] = array("class" => $klass, "icon" => $prop["widgetIcon"]);
			}
		}
		return $providers;
	}

	static function getRender($widget_name, $settings, $ws, $hs) {
		$plist = Providers::getProvidersList();
		$w = new $plist[$widget_name]["class"];
		$w->setTunables($settings);
		if ($ws > 0 && $hs > 0) {
			$w->width = $ws;
			$w->height = $hs;
		}

		return $w->render();
	}
};

// Image rendering stuff

function renderSVG($id) {
	header('Content-type: image/svg+xml');

	// Read the screen and parse it as JSON
	$scr = file_get_contents("screens/".$id);
	$scr = json_decode($scr, true);


        $bbox = array('t' => 65535, 'l' => 65535, 'b' => -65535, 'r' => -65535);
	$body = array();
	for ($i = 0; $i < count($scr["widgets"]); $i++) {
		$widget = $scr["widgets"][$i];
		$params = array();
		foreach ($widget["params"] as $p => $v)
			$params[$p] = array("value" => $v);

		$wi = Providers::getRender($widget["type"], $params, $widget["geo"]["w"] * $scr["width"], $widget["geo"]["h"] * $scr["height"]);

                $x = $widget["geo"]["x"] * $scr["width"]; $y = $widget["geo"]["y"] * $scr["height"]; $w = $widget["geo"]["w"] * $scr["width"]; $h = $widget["geo"]["h"] * $scr["height"];
		$body[] = sprintf('<image x="%d" y="%d" width="%d" height="%d" xlink:href="%s" />', $x, $y, $w, $h, "data:image/svg+xml;base64,".base64_encode($wi));

                if ($x < $bbox['l']) $bbox['l'] = $x;
                if ($y < $bbox['t']) $bbox['t'] = $y;
                if ($x+$w > $bbox['r']) $bbox['r'] = $x+$w;
                if ($y+$h > $bbox['b']) $bbox['b'] = $y+$h;
	}

	$body = implode("\n", $body);

	$svg = sprintf('<svg width="%d" height="%d" version="1.1" xmlns="http://www.w3.org/2000/svg" 
	                  xmlns:xlink="http://www.w3.org/1999/xlink">
	                  %s
	                </svg>', $scr["width"], $scr["height"], $body);

	return array(
		"width"  => $scr["width"],
		"height" => $scr["height"],
                "bbox"   => $bbox,
		"svg"    => '<?xml version="1.0" encoding="UTF-8" standalone="no"?>'.$svg
	);
}

function renderBMP($id, $numc, $maxwidth, $maxheight) {
	// Render image
	$data = renderSVG($_GET["id"]);
	$svg = $data["svg"];
	// Convert to PNG and scale
	$im = new Imagick();
        // Create output palette as PGM
        $palette = sprintf('P2 1 %d 255\n', $numc);
        for ($i = 0; $i < $numc; $i++) $palette .= floor($i * 255 / ($numc - 1)).' ';
        $pal = new Imagick();
        $pal->readImageBlob($palette);

        // Because there is a bug in the SVG rendered in Imagick when the drawn elements are 
        // out of the viewport (they should be clipped and not be accounted for in the total width)
        // but they are only clipped, resulting in smaller picture with a large border, so we need to enlarge them at first, to crop the picture in the end
        $enlargeFactor = max(($data['bbox']['r'] - $data['bbox']['l']) / $data["width"], ($data['bbox']['b'] - $data['bbox']['t']) / $data["height"]);
        if ($enlargeFactor < 1.0) {
                // If the picture is smaller than the expected size, make it at least the expected size
                // Imagick does not play well in that case, and figure itself a bounding box that's not logic.
                // So, let it compute its bounding box, and enlarge the canvas to the final picture is exactly the size we expect (instead of resizing a small picture, let it draw to fit the expected size)
                $newIm = new Imagick();
                $newIm->readImageBlob($svg);
                $dim = $newIm->getImageGeometry();
                $enlargeFactor = min($data['width'] / $dim['width'], $data['height'] / $dim['height']); // Must fit at the minimum size here
        }
        $im->setResolution(72 * $enlargeFactor, 72 * $enlargeFactor);
        $im->setBackgroundColor('white');
	$im->readImageBlob($svg);
        $im = $im->flattenImages();
	$im->setImageFormat("png24");
//        $dim = $im->getImageGeometry();
//        var_dump($dim); exit(0);

	// Apply max sizes
	$width = $data["width"];
	$height = $data["height"];
	if ($width > $maxwidth && $maxwidth>0) {
		$ar = $width/$height;
		$width = $maxwidth;
		$height = $width / $ar;
	}
	if ($height > $maxheight && $maxheight>0) {
		$ar = $width/$height;
		$height = $maxheight;
		$width = $height * $ar;
	}

        $im->cropImage($width, $height, max(-$data['bbox']['l'], 0), max(-$data['bbox']['t'], 0));
        if (($data['bbox']['r'] - $data['bbox']['l']) < $width || ($data['bbox']['b'] - $data['bbox']['t']) < height) 
        {
                $im->setImagePage($width, $height, 0, 0);
                $im = $im->flattenImages();
        }
        else $im->setImagePage(0, 0, 0, 0);
//	$im->resizeImage($width, $height, imagick::FILTER_LANCZOS, 1);

	// Set to gray
	$im->transformImageColorspace(imagick::COLORSPACE_GRAY);
//	$im->posterizeImage($numc, imagick::DITHERMETHOD_NO);
        $im->mapImage($pal, false);

	if (isset($_GET["inv"]))
		$im->negateImage(false);

	return $im;
}


// RLE compression:
// Chunk header is one byte, decoded means:
//  0XXX XXXX: The following byte is repeated XXXXXXX times + 1 (from 1 to 128)
//  1XXX XXXX: Just copy the following XXXXXXX+1 bytes (means the pattern is not compressible)

// Function that performs RLE compression!

function img_compress($buf) {
	// Array to hold the number of repeated elements starting from that position
	$reps = array_fill(0, count($buf), 0);
	$prev = -1;
	for ($i = count($buf)-1; $i >= 0; $i--) {
		if ($buf[$i] != $prev)
			$ctr = 0;
		$ctr += 1;

		$reps[$i] = $ctr;
		$prev = $buf[$i];
	}

	$outb = array_fill(0, 60*1024, 0);
	$outp = 0;
	$i = 0;
	$accum = 0;
	while ($i < count($buf)) {
		$bytec = min($reps[$i], 128);
		$encoderle = ($bytec > 3);

		if ($encoderle || $accum == 128) {
			// Flush noncompressable pattern
			if ($accum > 0) {
				$b = $accum - 1;
				$b |= 0x80;
				$outb[$outp - $accum - 1] = $b;
				$accum = 0;
			}
		}

		if ($encoderle) {
			# Emit a runlegth
			$outb[$outp++] = $bytec-1;
			$outb[$outp++] = $buf[$i];
			$i += $bytec;
		} else {
			if ($accum == 0)
				$outp++;
			$outb[$outp++] = $buf[$i++];
			$accum++;
		}
	}

	# Make sure to flush it all
	if ($accum > 0) {
		$b = $accum - 1;
		$b |= 0x80;
		$outb[$outp - $accum - 1] = $b;
	}

	return $outb;
}

?>
