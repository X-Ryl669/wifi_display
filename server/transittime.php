<?php

require_once("provider.php");

class TransitTimeProvider implements ServiceProvider {
	// Widget properties
	static $widgetName = "Transit time";
	static $widgetIcon = "transit.svg";

        // Get one from here: https://msdn.microsoft.com/en-us/library/ff428642.aspx
	public $apiKey;
	public $width;
	public $height;
	public $start_latitude;
	public $start_longitude;
	public $end_latitude;
	public $end_longitude;
        public $via_latitude;
        public $via_longitude;

	function TransitTimeProvider() {
		$this->apiKey = "";
		$this->width = 400;
		$this->height = 200;
		$this->start_latitude = 5;
		$this->start_longitude = 0;
		$this->end_latitude = 5;
		$this->end_longitude = 0;
                $this->via_latitude = 0;
                $this->via_longitude = 0;
	}

    public function getTunables() {
		$out = array(
			"api"         => array("type" => "text", "display" => "API Key", "value" => $this->apiKey),
			"slat"         => array("type" => "fnum", "display" => "From Latitude", "value" => $this->start_latitude),
			"slong"        => array("type" => "fnum", "display" => "From Longitude", "value" => $this->start_longitude),
			"elat"         => array("type" => "fnum", "display" => "To Latitude", "value" => $this->end_latitude),
			"elong"        => array("type" => "fnum", "display" => "To Longitude", "value" => $this->end_longitude),
			"vlat"         => array("type" => "fnum", "display" => "Via Latitude", "value" => $this->via_latitude),
			"vlong"        => array("type" => "fnum", "display" => "Via Longitude", "value" => $this->via_longitude),
		);
		return $out;
	}
    public function setTunables($v) {
		$this->apiKey = $v["api"]["value"];
		$this->start_latitude = $v["slat"]["value"];
		$this->start_longitude = $v["slong"]["value"];
		$this->end_latitude = $v["elat"]["value"];
		$this->end_longitude = $v["elong"]["value"];
		$this->via_latitude = $v["vlat"]["value"];
		$this->via_longitude = $v["vlong"]["value"];
	}

    public function shape() {
		// Return default width/height
		return array(
			"width"       => $this->width,
			"height"      => $this->height,
			"resizable"   => true,
			"keep_aspect" => false,
		);
    }

    public function render() {
		if ($this->apiKey == "") return sprintf('<svg width="%d" height="%d" version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"></svg>', $this->width, $this->height);
		// Gather information from BingMap
                $url = $this->via_latitude ? 
                        "http://dev.virtualearth.net/REST/V1/Routes?wp.0=".$this->start_latitude."%2C".$this->start_longitude."&wp.2=".$this->end_latitude."%2C".$this->end_longitude."&vwp.1=".$this->via_latitude.','.$this->via_longitude."&optmz=timeWithTraffic&key=".$this->apiKey :
                        "http://dev.virtualearth.net/REST/V1/Routes?wp.0=".$this->start_latitude."%2C".$this->start_longitude."&wp.1=".$this->end_latitude."%2C".$this->end_longitude."&optmz=timeWithTraffic&key=".$this->apiKey;
		$raw = file_get_contents($url);
		if (strlen($raw) == 0) return sprintf('<svg width="%d" height="%d" version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"></svg>', $this->width, $this->height);

		$array = json_decode($raw,true);
		$transitTimeSec = $array['resourceSets'][0]['resources'][0]['travelDuration'];
		$transitTimeTrafficSec = $array['resourceSets'][0]['resources'][0]['travelDurationTraffic'];
		$fontSize = 35;
		$out = sprintf('<svg width="%d" height="%d" version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">
                                   <text text-anchor="start" x="0" y="%d" fill="black" style="font-size: %dpx; font-family: %s;">Transit time: %dmn</text>
                                   <text text-anchor="start" x="0" y="%d" fill="black" style="font-size: %dpx; font-family: %s;">Traffic impact: %dmn</text>
				</svg>', $this->width, $this->height, $fontSize, $fontSize, "Arial", $transitTimeTrafficSec / 60.0, 2 * $fontSize, $fontSize, "Arial", ($transitTimeTrafficSec - $transitTimeSec) / 60);
		return $out;

	}
};

?>
