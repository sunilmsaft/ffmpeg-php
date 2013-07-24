<?php

	include_once 'fffile.php';

	// wrapper around FFProbe to determine information about a file.
	
	class FFSource extends FFAbstractFile {
	
		public static $FFPROBE_PATH = 'ffprobe';
		
		function __construct ( $filepath = null ) {
			parent::__construct($filepath);	
		}
		
		public function inspect ( $filepath ) {
			
			// TODO maybe not secure?
			$escapedFilepath = escapeshellarg( trim($this->filepath, '"') );
			
			$json = shell_exec(self::$FFPROBE_PATH . " -i $escapedFilepath -of json=c=1 -loglevel quiet -show_format -show_streams -show_error");
			
			$info = json_decode($json, true);
			
			if ( !is_array($info) ) {
				throw new Exception('Error reading input');
			}
			
			if ( $info['error'] ) {
				throw new Exception( $info['error']['string'] );
			}
			
			$this->populate($info);
			
			return $info;
			
		}
		
		protected function populate ( $info ) {
			
			/** populate with info **/
			
			if ( $info['format'] ) {
				$this->format   = $info['format']['format_name'];
				$this->duration = $info['format']['duration'];
				$this->filesize = $info['format']['size'];
			}
				
			if ( $info['streams'] ) {
				foreach ( $info['streams'] as $stream ) {
					
					// only grab first stream of each type (hence continue statement)
					switch ( $stream['codec_type'] ) {
						case 'video':
						
							if ( $this->video ) continue;
							
							$this->video = array(
								'codec'     => $stream['codec_name'],
								'bitrate'   => $stream['bit_rate'],
								'width'     => $stream['width'],
								'height'    => $stream['height'],
								'framerate' => $stream['r_frame_rate']
							);
							
							break;
						case 'audio':
							
							if ( $this->audio ) continue;
							
							$this->audio = array(
								'codec'      => $stream['codec_name'],
								'bitrate'    => $stream['bit_rate'],
								'channels'   => $stream['channels'],
								'samplerate' => $stream['sample_rate']
							);
							
							break;
						case 'subtitle':
						
							if ( $this->text ) continue;
							
							$this->text = array(
								'codec'      => $stream['codec_name']
							);
							
							break;
					}
				}
			}
		}
		
		
	}