<?php

require_once("provider.php");

class TrafficProvider implements ServiceProvider {
	// Widget properties
	static $widgetName = "Traffic information";
	static $widgetIcon = "trafic.svg";
        static $tuneColors = false;

        // Get one from here: https://msdn.microsoft.com/en-us/library/ff428642.aspx
	public $apiKey;
	public $width;
	public $height;
	public $latitude;
	public $longitude;

	function TrafficProvider() {
		$this->apiKey = "";
		$this->width = 800;
		$this->height = 200;
		$this->colors = array("red" => "rgb(210,57,64)", "green" => "rgb(119,186,66)", "yellow" => "rgb(243,235,87)", "orange1" => "rgb(252,193,80)", "orange2" => "rgb(223,200,63)", "name" => "rgb(20,20,20)" );
		$this->latitude = 5;
		$this->longitude = 0;
	}

    public function getTunables() {
		$out = array(
			"api"         => array("type" => "text", "display" => "API Key", "value" => $this->apiKey),
			"lat"         => array("type" => "fnum", "display" => "Latitude", "value" => $this->latitude),
			"long"        => array("type" => "fnum",  "display" => "Longitude", "value" => $this->longitude)
		);
		if ($this->tuneColors)
			return array_merge(array(
				// Enable the lines below to allow editing the color that's matched and converted to the 4 levels of gray available, you usually don't need this
				"red"         => array("type" => "text", "display" => "Red color to match", "value" => $this->colors["red"]),
				"green"       => array("type" => "text", "display" => "Green color to match", "value" => $this->colors["green"]),
				"yellow"      => array("type" => "text", "display" => "Yellow color to match", "value" => $this->colors["yellow"]),
				"or1"         => array("type" => "text", "display" => "Orange1 color to match", "value" => $this->colors["orange1"]),
				"or2"         => array("type" => "text", "display" => "Orange2 color to match", "value" => $this->colors["orange2"]),
				"name"        => array("type" => "text", "display" => "Name color to match", "value" => $this->colors["name"]),
			), $out);
		return $out;
	}
    public function setTunables($v) {
		$this->apiKey = $v["api"]["value"];
		if ($this->tuneColors) {
			$this->colors["red"] = $v["red"]["value"];
			$this->colors["green"] = $v["green"]["value"];
			$this->colors["yellow"] = $v["yellow"]["value"];
			$this->colors["orange1"] = $v["or1"]["value"];
			$this->colors["orange2"] = $v["or2"]["value"];
			$this->colors["name"] = $v["name"]["value"];
		}
		$this->latitude = $v["lat"]["value"];
		$this->longitude = $v["long"]["value"];
	}

    public function shape() {
		// Return default width/height
		return array(
			"width"       => $this->width,
			"height"      => $this->height,
			"resizable"   => true,
			"keep_aspect" => true,
		);
    }

    public function render() {
		if ($this->apiKey == "") return sprintf('<svg width="%d" height="%d" version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"></svg>', $this->width, $this->height);
		// Gather information from BingMap
		$raw = file_get_contents(
			"http://dev.virtualearth.net/REST/V1/Imagery/Map/Road/".$this->latitude."%2C".$this->longitude."/12?mapSize=".(int)$this->width.",".(int)$this->height."&mapLayer=TrafficFlow&format=png&key=".$this->apiKey
		);
		if (strlen($raw) == 0) return sprintf('<svg width="%d" height="%d" version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"></svg>', $this->width, $this->height);

		$max = \Imagick::getQuantumRange()["quantumRangeLong"];

		// For initial code see below
		$img = new Imagick();
		$img->readImageBlob($raw);

		$red = clone $img;
		// convert trafic.png -channel rgba -alpha on -fuzz 5% -fill none +opaque ".$this->colors["red"]." -fuzz 5% -fill rgb\(0,0,0\) -opaque ".$this->colors["red"]." redTrafic.png";
		$red->transparentPaintImage($this->colors["red"], 0.0, 0.05 * $max, true);
		$red->paintOpaqueImage($this->colors["red"], "black", 0.05 * $max);

		// convert trafic.png -channel rgba -alpha on -fuzz 5% -fill none +opaque rgb\(119,186,66\) -fuzz 5% -fill rgb\(192,192,192\) -opaque rgb\(119,186,66\) greenTrafic.png
		$green = clone $img;
		$green->transparentPaintImage($this->colors["green"], 0.0, 0.05 * $max, true);
		$green->paintOpaqueImage($this->colors["green"], "rgb(192,192,192)", 0.05 * $max);

		// convert trafic.png -channel rgba -alpha on -fuzz 5% -fill none +opaque rgb\(243,235,87\) -fuzz 5% -fill rgb\(128,128,128\) -opaque rgb\(243,235,87\) yellowTrafic.png
		$yellow = clone $img;
		$yellow->transparentPaintImage($this->colors["yellow"], 0.0, 0.05 * $max, true);
		$yellow->paintOpaqueImage($this->colors["yellow"], "rgb(128,128,128)", 0.05 * $max);

		// convert trafic.png -channel rgba -alpha on -fuzz 9% -fill none +opaque rgb\(252,193,80\) -fuzz 5% -fill none -opaque rgb\(223,200,63\) -median 3x3 -fuzz 9% -fill rgb\(32,32,32\) -opaque rgb\(252,193,80\) orangeTrafic.png
		$orange = clone $img;
		$orange->transparentPaintImage($this->colors["orange1"], 0.0, 0.09 * $max, true);
		$orange->transparentPaintImage($this->colors["orange2"], 0.0, 0.05 * $max, false);
		// You need Imagick at least 3.3 for this line to work
		$orange->statisticImage(\Imagick::STATISTIC_MEDIAN, 3, 3, \Imagick::CHANNEL_ALL);
		$orange->paintOpaqueImage($this->colors["orange1"], "rgb(32,32,32)", 0.09 * $max);

		// convert trafic.png -colorspace rgb -separate -delete 0,2 -channel rgba -alpha on -fuzz 20% -fill none +opaque rgb\(20,20,20\) names.png
		$name = clone $img;
		$name->separateImageChannel(\Imagick::CHANNEL_GREEN);
		$name->transformImageColorspace(\Imagick::COLORSPACE_RGB);
		$name->transparentPaintImage($this->colors["name"], 0.0, 0.20 * $max, true);

		// convert names.png -page 0x0+0+0 -fuzz 5% -fill rgb\(64,64,64\) -opaque rgb\(119,186,66\) greenTrafic.png -page 0x0+0+0 yellowTrafic.png -page 0x0+0+0 orangeTrafic.png -page 0x0+0+0 redTrafic.png -flatten -crop 800x190+0+0 result.png
		$result = clone $name;
		$result->setImageBackgroundColor(new ImagickPixel('white'));
		$green->setImagePage($green->getImageWidth(), $green->getImageHeight(), 0, 0);
		$result->compositeimage($green, Imagick::COMPOSITE_DEFAULT, 0, 0);
		$result->compositeimage($yellow, Imagick::COMPOSITE_DEFAULT, 0, 0);
		$result->compositeimage($orange, Imagick::COMPOSITE_DEFAULT, 0, 0);
		$result->compositeimage($red, Imagick::COMPOSITE_DEFAULT, 0, 0);
		$result->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
		$result->cropImage($this->width, $this->height - 10, 0, 0); // Remove copyright and logo so it does not break dithering

		// convert result.png -channel rgba -alpha on -fill transparent -opaque white resultT.png
		$resultT = clone $result;
		$resultT->transparentPaintImage("white", 0.0, 0, false);

		// convert trafic.png -set colorspace Gray -separate -average -dither none -colors 4 -depth 2 gray.png
		// This is the most complex RGB to gray function I've ever seen
		$tmpR = clone $img; $tmpR->separateimagechannel(\Imagick::CHANNEL_RED);
		$tmpG = clone $img; $tmpG->separateimagechannel(\Imagick::CHANNEL_GREEN);
		$gray = clone $img; $gray->separateimagechannel(\Imagick::CHANNEL_BLUE);
		$gray->addImage($tmpR);//, Imagick::COMPOSITE_DEFAULT, 0, 0);
		$gray->addImage($tmpG);//, Imagick::COMPOSITE_DEFAULT, 0, 0);
		$gray->setImageColorspace(\Imagick::COLORSPACE_GRAY);
		$gray->resetIterator();
		$gray = $gray->averageImages();
		$gray->quantizeImage(4, \Imagick::COLORSPACE_GRAY, 0, false, false);//\Imagick::DITHERMETHOD_NO);
		$gray->setImageDepth(2);

		// convert gray.png -channel rgba -alpha on resultT.png -compose Over  -composite -crop 800x190+0+0 -gamma 0.95 -dither none -colors 4 -depth 2 final.png
		$final = new Imagick();
		$final->newImage($img->getImageWidth(), $img->getImageHeight(), new \ImagickPixel('rgb(32,32,32)'));
		$final->setImageFormat("png");
		$final->compositeimage($gray, Imagick::COMPOSITE_BLEND, 0, 0);//, \Imagick::CHANNEL_ALL);
		$final->compositeimage($resultT, Imagick::COMPOSITE_OVER, 0, 0);//, \Imagick::CHANNEL_ALL);
		$final->cropImage($this->width, $this->height - 10, 0, 0);
		$final->gammaImage(0.95, \Imagick::CHANNEL_ALL);
		$palette = new Imagick("resources/einkPalette.png");
		$final->remapImage($palette, \Imagick::DITHERMETHOD_NO); // Make sure the final picture is using only 4 colors with value 0, 85, 170, 255
		
		$pic = $final->getImageBlob();
                $daily = sprintf('<image x="0" y="0" width="%d" height="%d" xlink:href="%s" />', $this->width, $this->height - 10, 'data:image/png;base64,' . base64_encode($pic));

		// Generate an SVG image out of this 
		return sprintf(
			'<svg width="%d" height="%d" version="1.1" xmlns="http://www.w3.org/2000/svg" 
				xmlns:xlink="http://www.w3.org/1999/xlink">
				%s
			</svg>',
				$this->width, $this->height,
				$daily
		);
	}
};

?>
