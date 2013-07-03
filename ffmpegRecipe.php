<?php
	
	class FFmpegRecipe {
	
		// TODO should this default to true or false?
		public $allowOverwrite = true;
	
		private $arguments;
		private $filters;
	
		public static function fromFile ( $filepath, FFmpegRecipe $instance = null ) {
			
			$recipe = ($instance) ? $instance : new FFmpegRecipe();
			
			if ( !is_string($filepath) || !file_exists($filepath) ) {
				throw new Exception('ffpreset file does not exist');
			}
			
			$pathinfo = pathinfo($filepath);
			if ( $pathinfo['extension'] != 'ffpreset' ) {
				throw new Exception('Recipe preset must have ffpreset extension');
			}
			
			$fileHandle = fopen($filepath, 'r');
			
			if ( !$fileHandle ) {
				throw new Exception('Unable to open ffpreset for reading');
			}
			
			while ( !feof($fileHandle) ) {
				$buffer = fgets($fileHandle, 256);
				// lines starting with a # are comments
				if ($buffer[0] == "#") {
print "skipping comment: $buffer\n";
					continue;
				}
				
				// TODO is this a possible attack vector?  perhaps necessary to filter for malicious arguments
				list($key, $value) = explode('=', trim($buffer), 2);
				if ( $key && $value ) {
					$recipe->set($key, $value);
				} else {
print "no match: $key, $value, $buffer\n";
				}
			}
			
			fclose($fileHandle);
			
			return $recipe;
			
		}

		public static function fromJSON ( $json, FFmpegRecipe $instance = null ) {
			
			
			$recipe = ($instance) ? $instance : new FFmpegRecipe();
			
			$array = json_decode($json, true);
			
			if ( !is_array($array) ) {
				throw new Exception('Unable to parse JSON');
			}
			
			// TODO need safety/sanity check
			foreach($array as $key=>$value) {
				$recipe->set($key, $value);
			}
			
			return $recipe;
			
		}
		
		function __construct ( array $inputArguments = null ) {
			$this->arguments = array();
			$this->filters = array();
			
			if ( $inputArguments ) {
				// TODO need safety/sanity check
				foreach ( $array as $key=>$value ) {
					$recipe->set($key, $value);
				}	
			}
			
			
		}
		
		public function set ( $key, $value ) {
			if ( $key == 'vf' ) {
				$this->filters[] = $value;
			} elseif ( $key == 'y' ) {
				$this->allowOverwrite = true;
			} else {
				$this->arguments[$key] = $value;
			}
		}
		
		public function asArgumentsString () {
			$args = "";
			foreach ( $this->arguments as $key => $value ) {
				$args .= " -$key $value";
			}
			
			foreach ( $this->filters as $value ) {
				$args .= " -vf $value";
			}
			
			if ($this->allowOverwrite) {
				$args .= " -y";
			}
			
			return $args;
		
		}
		
		public function constrainSize( $maxWidth = null, $maxHeight = null) {
			
			if ( $maxWidth && (($maxWidth > 4096) || ($maxWidth < 16)) ) {
				throw new Exception('Invalid width');
			}
			
			if ( $maxHeight && (($maxHeight > 4096) || ($maxHeight < 16)) ) {
					throw new Exception('Invalid height');
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
				$this->arguments['t'] = (float) $duration;
			}
		
		}
	}
?>