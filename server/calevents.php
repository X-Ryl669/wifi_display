<?php

require_once("provider.php");
require_once("CalDAVClient.php");
require_once("ICal.php");

class CalendarProvider implements ServiceProvider {
	// Widget properties
	static $widgetName = "Next calendar events";
	static $widgetIcon = "caldav.svg";

        // Figure out the calendar URL via http://ntbab.dyndns.org/apache2-default/seite/caldavprovider.html
	public $caldavURL;
	public $width;
	public $height;
	public $delay; // In hours

	function CalendarProvider() {
		$this->caldavURL = "";
		$this->width = 800;
		$this->height = 200;
		$this->delay = 24;
		$this->font_size = 1;
		$this->font_family = "Verdana";
	}

	public function getTunables() {
		return array(
			"url"         => array("type" => "text", "display" => "Calendar URL (use comma for multiple calendar)", "value" => $this->caldavURL),
			"delay"       => array("type" => "fnum", "display" => "Upcoming hours", "value" => $this->delay),
			"font_family" => array("type" => "text", "display" => "Font Family", "value" => $this->font_family),
			"font_size"   => array("type" => "fnum", "display" => "Font Size", "value" => $this->font_size)
		);
	}
	public function setTunables($v) {
		$this->caldavURL   = $v["url"]["value"];
		$this->delay       = $v["delay"]["value"];
		$this->font_family = $v["font_family"]["value"];
		$this->font_size   = $v["font_size"]["value"];
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

        private function makeTime($shift = 0) {
                return strftime("%G%m%dT%H%M%SZ", time() + ($shift * 3600));
        }
	private function fromTime($time) {
		return substr($time, 9, 2).":".substr($time,11,2);
	}

	public function render() {
		if ($this->caldavURL == "") return sprintf('<svg width="%d" height="%d" version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"></svg>', $this->width, $this->height);

                $events = array();
                $urls = explode(",", $this->caldavURL);
		$maxLen = 0;
                foreach($urls as $url) {
                        $u = parse_url($url);
			$cleanURL = $u["scheme"]."://".$u["host"]. (isset($u["port"]) ? ':'.$u["port"] : '').$u["path"].(isset($u["query"]) ? '?'.$u['query'] : '');
                        $cal = new CalDAVClient($cleanURL, $u["user"], $u["pass"]);
                        $evs = $cal->GetEvents($this->makeTime(0), $this->makeTime($this->delay));
                        $v = "";
                        foreach ($evs as $ev) {
                                if (strlen($v)) $v.= "\n";
                                $v .= $ev['data'];
                        }
                        // Then parse the events from the ics content now
                        $ical = new ICal\ICal(explode("\n", $v));
                        $evs = $ical->eventsFromRange($this->makeTime(0), $this->makeTime($this->delay));
                        if ($evs) foreach ($evs as $ev) {
                                $events[] = array('user' => $u['user'], 'time' => $ev->dtstart, 'text' => strlen($ev->summary) ? $ev->summary : $ev->description, 'end' => $ev->dtend);
				$maxLen = max($maxLen, strlen($u['user'])+9+strlen($ev->summary));
                        }
                }

                // Need to figure out the maximum size for each event so we can deduce the best font size
                $nbEvents = count($events);
		if (!$nbEvents) return sprintf('<svg width="%d" height="%d" version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><text text-anchor="start" x="0" y="%d" fill="#AAAAAA" style="font-size: %dpx; font-family: %s;">No upcoming events</text></svg>', $this->width, $this->height, $this->height * 4 / 6, $this->height / 3.2, $this->font_family);

		$fontSize = min(floor($this->height / $nbEvents), floor($this->width / $maxLen));
		$ar = $this->height / $this->width;
		$fontSize = floor($this->height / max($nbEvents, 3));// * $ar;
		$text = ""; $i = 0;
                $prevDay =  $maxLen ? substr($events[0]['time'], 0, 8) : '';
		foreach($events as $ev) {
			$text .= sprintf('<rect x="%d" y="%g" height="%d" width="%d" rx="10" ry="10" fill="#AAAAAA" />', 0, $i + $fontSize * 0.19, floor($fontSize * 0.9), $this->width / 6.0 + 8);
                        // Because support for textLength and lenghtAdjust is missing in RSVG and inkscape, ImageMagick renders these lines below ugly.
/*
			$text .= sprintf('<text text-anchor="start" x="%d" y="%d" textLength="%d" lengthAdjust="spacingAndGlyphs" fill="#FFFFFF" style="font-size: %dpx; font-family: %s;">%s</text>', 0, $i+floor($fontSize * 0.9), $this->width/6.0, floor($fontSize * 0.8), $this->font_family, ucfirst($ev['user']));
			$text .= sprintf('<text text-anchor="start" x="%d" y="%d" textLength="%d" lengthAdjust="spacingAndGlyphs" fill="#555555" style="font-size: %dpx; font-family: %s;">%s</text>', $this->width / 6.0 + 16, $i+$fontSize, $this->width / 8.0, $fontSize, $this->font_family, $this->fromTime($ev['time'])); */
                        // So, we need to compute the actual size for the text by ourselves
                        $nameLen = calculateTextBox(ucfirst($ev['user']), $this->font_family, $fontSize * 0.8, 0);
                        $timeLen = calculateTextBox($this->fromTime($ev['time']), $this->font_family, $fontSize, 0);
                        // Draw a line when changing day in the event list
                        $day = substr($ev['time'], 0, 8);
                        if ($day != $prevDay) { $text .= sprintf('<rect x="0" y="%g" height="1" width="%d" fill="#000000" />', $i + 3, $this->width); $prevDay = $day; }
                        // Then compute the text transform scale
                        $text .= sprintf('<text text-anchor="start" x="%d" y="%d" fill="#FFFFFF" style="font-size: %dpx; font-family: %s;" transform="translate(0, 0) scale(%g, 1.0)">%s</text>', 0, $i+floor($fontSize * 0.9),
                                                floor($fontSize * 0.8), $this->font_family, $this->width/6.0 / $nameLen['width'],ucfirst($ev['user']));
                        $text .= sprintf('<text text-anchor="start" x="0" y="%d" fill="#555555" style="font-size: %dpx; font-family: %s;" transform="translate(%d, 0) scale(%g, 1.0)">%s</text>', $i+$fontSize, 
                                                $fontSize, $this->font_family, $this->width / 6.0 + 16, $this->width/8.0 / $timeLen['width'], $this->fromTime($ev['time']));
			$text .= sprintf('<text text-anchor="start" x="%d" y="%d" fill="black" style="font-size: %dpx; font-family: %s;">%s</text>', $this->width/8.0 + $this->width/6.0 + 32, $i+$fontSize, $fontSize, $this->font_family, $ev['text']);
                        

			$i += $fontSize;
		}

		// Generate an SVG image out of this 
		return sprintf(
			'<svg width="%d" height="%d" version="1.1" xmlns="http://www.w3.org/2000/svg" 
				xmlns:xlink="http://www.w3.org/1999/xlink">
				%s
			</svg>',
				$this->width, $this->height,
				$text
		);
	}
};

?>
