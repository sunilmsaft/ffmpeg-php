<?php

	include_once 'fffile.php';
	
	class FFDestination extends FFAbstractFile {
		
		// TODO include default options?
	
		// TODO should this default to true or false?
		public $allowOverwrite = true;
	
		function __construct ( $input = null ) {
			parent::__construct();
			
			if ( is_array($input) ) {
				$this->setArray($input, $this);
			} elseif ( is_string($input) ) {
				$this->loadPreset($input, $this);				
			}
			
		}
		
		public function loadPreset ( $filepath ) {
			
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
				
				// empty or comment
				if ( !$matches || $matches[1] === '#' ) {
					// nothing
				// else special directive or values to set
				} elseif ( $matches[1] === '#@' || $matches[2] && ($matches[3] !== null) ) {
					$this->set($matches[2], $matches[3]);
				}
			}
			
			fclose($fileHandle);
			
		}
		
		
		public function setArray ( array $arguments ) {
		
			foreach ( $arguments as $key=>$value ) {
				$this->set($key, $value);
			}
			
		}
		
		public function set ( $key, $value ) {
			switch ( $key ) {
				// filesystem
				case 'y'        : 
				case 'overwrite': $this->allowOverwrite = true; break;
				
				case 'extension': $this->setFilepath(array('extension' => $value)); break;
				case 'directory': $this->setFilepath(array('directory' => $value)); break;
				
				// TODO convert 00:00:00 duration to seconds?
				case 't'        :
				case 'duration' : $this->duration = $value; break;
				
				case 'f'        :
				case 'format'   : $this->format = $value; break;
				
				case 'vcodec'   :                                     
				case 'c:v'      : $this->video['codec'] = $value; break;
				// TODO convert pretty (1024k, 10M) to bytes
				case 'b'        :                                     
				case 'b:v'      : $this->video['bitrate'] = $value; break;
				case 'r'        : $this->video['framerate'] = $value; break;
				case 's'        :
					list($this->width, $this->height) = explode('x', $value);
					break;
				case 'width'    : $this->width = $value; break;
				case 'height'   : $this->height = $value; break;
				case 'rotate'   : $this->rotation = $value; break;
				
				case 'acodec'   :                                   
				case 'c:a'      : $this->audio['codec'] = $value; break;
				// TODO convert pretty (1024k, 10M) to bytes
				case 'ab'       :                                     
				case 'b:a'      : $this->audio['bitrate'] = $value; break;
				case 'ac'       : $this->audio['channels'] = $value; break;
				case 'ar'       : $this->audio['samplerate'] = $value; break;
				
				case 'c:s'      : $this->text['codec'] = $value; break;
				
				default:
					// multiple value support -- if defined multiple times then create array
					if ( array_key_exists($key, $this->extraArguments) ) {
						if ( is_array($this->extraArguments[$key]) ) {
							$this->extraArguments[$key][] = $value;
						} else {
							$this->extraArguments[$key] = array($this->extraArguments[$key], $value);
						}
					} else {
						$this->extraArguments[$key] = $value;
					}
			}
		}
		
		// NOTE doesn't include output filepath
		public function asArgumentsString () {
			$args = "";
			
			if ( $this->rotation ) {
				$this->rotate($this->rotation);
			}
			
			if ( $this->width || $this->height ) {
				$this->constrainSize( $this->width, $this->height );
			}
			
			foreach ( $this->extraArguments as $key => $value ) {
				// multiple values support
				if ( is_array($value) ) {
					$args .= " -$key " . implode(" -$key ", $value);
				} else {
					$args .= " -$key $value";
				}
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
		
		// NOTE the below functions are only used when constructing argument string
		
		private function constrainSize( $maxWidth = null, $maxHeight = null) {
			
			$filter = '';
			
			if ( $maxWidth && (($maxWidth > 4096) || ($maxWidth < 16)) ) {
				throw new Exception("Invalid width: $maxWidth");
			}
			
			if ( $maxHeight && (($maxHeight > 4096) || ($maxHeight < 16)) ) {
					throw new Exception("Invalid height: $maxHeight");
			}
			
			if ( $maxWidth && $maxHeight ) {
				$filter = "scale=\"trunc(iw*min($maxWidth/iw\, $maxHeight/ih)/2)*2:trunc(ih*min($maxWidth/iw\, $maxHeight/ih)/2)*2\"";				
			} elseif ( $maxWidth ) {
				$filter = 'scale="min(' . $maxWidth . '\, iw):trunc(ow/a/2)*2"';
			} elseif ( $maxHeight ) {
				$filter = 'scale="trunc(oh*a/2)*2:min(' . $maxHeight . '\, ih)"';
			}
			
			$this->set('vf', $filter);
			
		}
				
		private function rotate ( $rotation ) {
			
			$filter = '';
			
			switch ( (int) $rotation ) {
				case 0:
					break;
				case 90:
					$filter = "transpose=1";
					break;
				case -90:
					$filter = "transpose=2";
					break;
				case 180:
				case -180:
					// flip upside down and horizontally to avoid mirroring
					$filter = "hflip,vflip";
					break;
				default:
					throw new Exception('Rotation should be degrees: 0, 90, -90, 180 or -180');
					break;
			}
			
			$this->set('vf', $filter);
			
		}
		
	}