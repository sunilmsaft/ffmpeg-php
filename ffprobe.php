<?php

	class FFprobe {
	
		public static $FFPROBE_PATH = 'ffprobe';
	
		public $filename;
		public $container;
		public $video;
		public $audio;
		public $text;
		
		function __construct ( $filename ) {
			
			if ( $filename ) {
				$this->filename = (string) $filename;
				
				$this->json = $this->probe($this->filename);
				$this->parse($this->json);
			}
			
		}
		
		// singleton access method FFprobe()
		static function open ( $filename ) {
			if ( !is_readable($filename) ) {
				throw new Exception('Unable to open media file');
			}
			
			return new FFprobe( $filename );
		}
		
		function probe ( $filename ) {
			
			// TODO maybe not secure
			$escapedFilename = escapeshellarg( trim($filename, '"') );
			
			$json = shell_exec(self::$FFPROBE_PATH . " -i $escapedFilename -of json=c=1 -loglevel quiet -show_format -show_streams -show_error");
			
			$data = json_decode($json, true);
			
			if ( !is_array($data) ) {
				throw new Exception('Unable to parse JSON');
			}
			
			if ( $data['error'] ) {
				throw new Exception( $data['error']['string'] );
			}
			
			return $data;
			
		}
		
		function parse ( $data ) {
			
			/** populate with info **/
			
			if ( $data['format'] ) {
				$this->container = array(
					'format'   => $data['format']['format_name'],
					'duration' => $data['format']['duration'],
					'size'     => $data['format']['size']
				);
			}
			
			if ( $data['streams'] ) {
				foreach ( $data['streams'] as $stream ) {
					
					// only grab first stream of each type
					switch ( $stream['codec_type'] ) {
						case 'video':
						
							if ( $this->video ) continue;
							
							$this->video = array(
								'codec'     => $stream['codec_name'],
								'bitrate'   => $stream['bit_rate'],
								'width'     => $stream['width'],
								'height'    => $stream['height'],
								'framerate' => $stream['r_frame_rate'],
								'sar'       => $stream['sample_aspect_ratio'],
								'dar'       => $stream['display_aspect_ratio']
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
								'codec'      => $stream['codec_name'],
								'bitrate'    => $stream['bit_rate']
							);
							
							break;
					}
				}
			}
			
			return $this;	
		}
		
		function save ( $filename ) {
			if ( !is_writable($filename) ) {
				throw new Exception('FFpreset: Output file is not writable');
			}
			
			file_put_contents( (string) $filename, json_encode($this->json) );
		}
	}
	
	
?>