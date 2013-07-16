<?php

	class FFmpegFile {
	
		public static $FFPROBE_PATH = 'ffprobe';
	
		public $container;
		public $video;
		public $audio;
		public $text;
		
		function __construct ( $filename ) {
			
			if ( $filename ) {
				$this->filename = (string) $filename;
				$this->probe($this->filename);
			}
			
		}
		
		function probe ($filename) {
		
			$json = shell_exec(self::$FFPROBE_PATH . " -i {$filename} -of json=c=1 -loglevel quiet -show_format -show_streams -show_error");
			$data = json_decode($json, true);
			
			if ( $data['error'] ) {
				throw new Exception( $data['error']['string'] );
			}
			
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
	}
	
?>