<?php

class FFfile {
	
	public $filename;
	public $directory;
	public $extension;
	
	public $format;
	public $duration;
	public $filesize;
	
	public $video;
	public $audio;
	public $text;
	
	public $arguments;
	
	// TODO only supports single streams of each type
	function __construct ( ) {
		
		$this->video = array_fill_keys(array('codec','bitrate','width','height','framerate','details'), null);
		
		$this->audio = array_fill_keys(array('codec','bitrate','channels','samplerate','details'), null);
		
		$this->text  = array_fill_keys(array('codec','details'), null);
		
		$this->arguments = array();
		
	}
	
	function set ( $key, $value ) {
	
	}

}