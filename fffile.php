<?php

class FFAbstractFile {
	
	// TODO should we store just filepath and parse as needed, or separate on load?
	public $filepath;
	public $basename;
	public $directory = '.';
	public $extension;
	
	
	public $format;
	public $duration;
	public $filesize;
	
	public $video;
	public $audio;
	public $text;
	
	public $extraArguments;
	
	// TODO only supports single streams of each type
	function __construct ( $filepath = null ) {
		
		$this->video = array_fill_keys(array('codec','bitrate','width','height','framerate','details'), null);
		
		$this->audio = array_fill_keys(array('codec','bitrate','channels','samplerate','details'), null);
		
		$this->text  = array_fill_keys(array('codec','details'), null);
		
		$this->extraArguments = array();
		
		// pre-compute file information
		if ( is_string($filepath) ) {
			$this->setFilepath($filepath);
		}		
	}
	
	// if path is array then only replace parts, i.e. array('directory'=>'something') only replaces directory
	function setFilepath ( $path ) {
		if ( is_array($path) ) {
			$pathinfo = $path;
		} else {
			$pathinfo = pathinfo($path);
		}

		$this->filename  = $pathinfo['filename']  ?: $this->filename;
		$this->directory = $pathinfo['dirname']   ?: $this->directory;
		$this->extension = $pathinfo['extension'] ?: $this->extension;
		
		$this->filepath = "{$this->directory}/{$this->filename}.{$this->extension}";
	}
	
	function set ( $key, $value ) {
	
	}

}