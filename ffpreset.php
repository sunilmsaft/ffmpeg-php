<?php

	include_once 'fffile.php';
	
	class FFpreset extends FFfile {
	
	
		// TODO should this default to true or false?
		public $allowOverwrite = true;
	
		public $filters;
		
		public static function fromFile ( $filepath, FFpreset $instance = null ) {
			
			$preset = $instance ?: new FFpreset();
			
			if ( !is_string($filepath) || !file_exists($filepath) ) {
				throw new Exception('ffpreset file does not exist');
			}
			
			$pathinfo = pathinfo($filepath);
			if ( $pathinfo['extension'] != 'ffpreset' ) {
				throw new Exception('Preset must have .ffpreset extension');
			}
			
			$fileHandle = fopen($filepath, 'r');
			
			if ( !$fileHandle ) {
				throw new Exception('Unable to open ffpreset for reading');
			}
			
			while ( !feof($fileHandle) ) {
				$buffer = fgets($fileHandle, 256);
				
				// lines starting with a # are comments or special instructions
				$matches = array();
				preg_match('/^(#|#@|)([a-zA-Z]*)=(.+)/', trim($buffer), $matches);
				
				if ( !$matches || $matches[1] === '#' ) {
					// empty or commentempty
				} elseif ( $matches[1] === '#@' ) {
					$preset->set($matches[2], $matches[3]);
				} elseif ( $matches[2] && ($matches[3] != null) ) {
					$preset->set($matches[2], $matches[3]);
				}
			}
			
			fclose($fileHandle);
			
			return $preset;
			
		}
		
		public static function load ( $filename ) {
			return FFpreset::fromFile( $filename );
		}

		public static function fromJSON ( $json, FFpreset $instance = null ) {
			
			$array = json_decode($json, true);
			
			if ( !is_array($array) ) {
				throw new Exception('Unable to parse JSON');
			}
			
			return FFpreset::fromArray($array, $instance);
			
		}
		
		public static function fromArray ( array $arguments, FFpreset $instance = null ) {
		
			$preset = $instance ?: new FFpreset();
			
			foreach ( $arguments as $key=>$value ) {
				$preset->set($key, $value);
			}
			
			return $preset;
			
		}
		
		function __construct ( $input = null ) {
			$this->arguments = array();
			$this->filters = array();
			
			if ( is_array($input) ) {
				FFpreset::fromArray($input, $this);
			} elseif ( is_string($input) && preg_match('/^[\[\{]\"/', $input) ) {
				FFpreset::fromJSON($input, $this);
			} elseif ( is_string($input) ) {
				FFpreset::fromFile($input, $this);				
			}
			
		}
		
		public function set ( $key, $value ) {
			switch ( $key ) {
				case 'vf'       : $this->filters[] = $value;          break;
				case 'y'        : $this->allowOverwrite = true;       break;
				case 'extension': $this->extension           = $value; break;
				
				// TODO convert 00:00:00 duration to seconds?
				case 't'        :
				case 'duration' : $this->duration            = $value; break;
				
				case 'f'        :
				case 'format'   : $this->format              = $value; break;
				
				case 'vcodec'   :                                     
				case 'c:v'      : $this->video['codec']      = $value; break;
				
				// TODO convert pretty (1024k, 10M) to bytes
				case 'b'        :                                     
				case 'b:v'      : $this->video['bitrate']    = $value; break;
				case 'r'        : $this->video['framerate']  = $value; break;
				case 's'        :
					list($this->width, $this->height) = explode('x', $value);
					break;
				case 'width'    : $this->width               = $value; break;
				case 'height'   : $this->height              = $value; break;
				case 'rotate'   : $this->rotation            = $value; break;
				
				case 'acodec'   :                                   
				case 'c:a'      : $this->audio['codec']      = $value; break;
				// TODO convert pretty (1024k, 10M) to bytes
				case 'ab'       :                                     
				case 'b:a'      : $this->audio['bitrate']    = $value; break;
				case 'ac'       : $this->audio['channels']   = $value; break;
				case 'ar'       : $this->audio['samplerate'] = $value; break;
				
				case 'c:s'      : $this->text['codec']       = $value; break;
				
				default:
					$this->arguments[$key] = $value;
					break;
			}
		}
		
		public function asArgumentsString () {
			$args = "";
			foreach ( $this->arguments as $key => $value ) {
				$args .= " -$key $value";
			}
			
			if ( $this->rotation ) {
				$this->rotate($this->rotation);
			}
			
			if ( $this->width || $this->height ) {
				$this->constrainSize( $this->width, $this->height );
			}
			
			foreach ( $this->filters as $value ) {
				$args .= " -vf $value";
			}
			
			if ( $this->format ) {
				$args .= " -f {$this->format}";
			}
			
			if ( $this->duration ) {
				$args .= " -t {$this->duration}";
			}
			
			if ($this->allowOverwrite) {
				$args .= " -y";
			}
			
			return $args;
		
		}
		
		public function constrainSize( $maxWidth = null, $maxHeight = null) {
			
			if ( $maxWidth && (($maxWidth > 4096) || ($maxWidth < 16)) ) {
				throw new Exception("Invalid width: $maxWidth");
			}
			
			if ( $maxHeight && (($maxHeight > 4096) || ($maxHeight < 16)) ) {
					throw new Exception("Invalid height: $maxHeight");
			}
			
			if ( $maxWidth && $maxHeight ) {
				$this->filters[] = "scale=\"trunc(iw*min($maxWidth/iw\, $maxHeight/ih)/2)*2:trunc(ih*min($maxWidth/iw\, $maxHeight/ih)/2)*2\"";				
			} elseif ( $maxWidth ) {
				$this->filters[] = 'scale="min(' . $maxWidth . '\, iw):trunc(ow/a/2)*2"';
			} elseif ( $maxHeight ) {
				$this->filters[] = 'scale="trunc(oh*a/2)*2:min(' . $maxHeight . '\, ih)"';
			}
			
		}
		
		public function rotate ( $rotation ) {
			
			switch ( (int) $rotation ) {
				case 0:
					break;
				case 90:
					$this->filters[] = "transpose=1";
					break;
				case -90:
					$this->filters[] = "transpose=2";
					break;
				case 180:
				case -180:
					$this->filters[] = "hflip";
					$this->filters[] = "vflip";
					break;
				default:
					throw new Exception('Rotation should be degrees: 0, 90, -90, 180 or -180');
					break;
			}
			
		}
		
		/**
		 * Select a portion of a file from a given offset
		 * Parameters follow array_slice syntax:
		 * @param {float} offset - Offset (in seconds) to start
		 * @param {float} duration - Duration (in seconds) of output file.
		 * 				  If null, 0 or negative then encode to end of input
		 */
		public function slice ( $offset, $duration=null ) {
			$this->arguments['ss'] = (int) $offset;
			
			if( (float) $duration > 0 ) {
				$this->duration = (float) $duration;
			}
		
		}
	}