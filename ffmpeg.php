<?php

include_once "FFfile.php";
include_once "FFpreset.php";
include_once "FFprobe.php";


/**
 * FFmpeg
 * encodes files using ffmpeg as a non-blocking child process
 *
 * Usage:
 *
 * Create Job:
 * $job = new FFmpeg($inputFilePath, $outputFilePath, $recipe);
 *
 * Start Job:
 * $job->start();
 *
 * Check if Job is finished (returns boolean):
 * $job->isActive();
 *
 * Get current status (returns associative array of ffmpeg progress:
 * $statistics = $job->getStatus()
 *
 */
class FFmpeg {

	const READ_LENGTH = 256;
	const STDIN = 0;
	const STDOUT = 1;
	const STDERR = 2;
	// change if necessary
	public static $FFMPEG_PATH = 'ffmpeg';

	public $input;
	public $output;
	public $recipe;
	
	public $progress;
	public $duration;
	
	// set true to use the same directory as input file by default
	public static $SAME_DIRECTORY = true;
	// set true to use the same filename as input file by default
	public static $SAME_FILENAME = true;
	
	private $pid;
	private $exitcode;
	
	private $process=0;
	private $pipes;
	
	

	function __construct ( $input, $output ) {
		
		$this->setInput($input);
		$this->setOutput($output);
		
	}
	
	// by default use ffprobe to get information about file
	public function setInput ( $input, $inspect = false ) {
		$this->input = ( $input instanceof FFAbstractFile ) ? $input : new FFSource($input);
		
		if ( $inspect ) {
			$this->input->inspect();
			// get duration information from file
			$this->duration = $this->input->duration;
		}
		
	}
		
	public function setOutput ( $output ) {
		$this->output = ($output instanceof FFDestination ) ? $output : new FFDestination($output);
	}
	
	/**
	 * Select a portion of a file from a given offset
	 * Parameters follow array_slice syntax:
	 * @param {float} offset - Offset (in seconds) to start
	 * @param {float} duration - Duration (in seconds) of output file.
	 * 				  If null, 0 or negative then encode to end of input
	 */
	public function slice ( $offset, $duration=null ) {
		$this->output->set('ss', (float) $offset);
		
		if( (float) $duration > 0 ) {
			$this->output->set('duration', $duration);
		} else if ( (float) $duration < 0 ) {
			if ( !$this->duration ) {
				throw new Exception("Unable to slice file from end because duratio is unknown -- use the input file's 'inspect' option to read the video duration first");
			}
			
			$this->output->set('duration', $this->duration - ((float) $duration) - ((float) $offset));
		}
	
	}
	
	
	// TODO what's the most expected behavior for defaults / overwriting destination?
	private function validateOutputSettings ( ) {
		// TODO test - not sure that an empty directory will return false (may be '.')
		$newOutputPathinfo = array();
		if ( !$this->output->directory && $SAME_DIRECTORY ) {
			$newOutputPathinfo['directory'] = $this->input->directory;
		}
		
		if ( !$this->output->filename && $SAME_FILENAME ) {
			$newOutputPathinfo['filename'] = $this->input->filename;
		}
		
		if ( !empty($newOutputPathinfo) ) {
			$this->output->setFilepath($newOutputPathinfo);
		}
		
		if ( $this->output->filepath == $this->input->filepath ) {
			throw new Exception("Input/Output filename collision - change output path");
		}
		
	}
	
	public function start () {
		
		$this->validateOutputSettings();
		
		// todo ensure that preset input and output is set
		// TODO add checks of input to preset?
		$commandString = implode(' ', array(
			self::$FFMPEG_PATH,
			'-i', $this->input->filepath,
			$this->output->asArgumentsString(),
			$this->output->filepath
		));
	
		$this->pipes = (array) null;
		$descriptor = array (
			array ("pipe", "r"), // in
			array ("pipe", "w"), // out
			array ("pipe", "w")  // err
		);
		
		//Open the resource to execute $command
		$this->process = proc_open(
			$commandString,
			$descriptor,
			$this->pipes,
			null,
			null,
			array('bypass_shell'=>true)
		);
		
		//Set STDOUT and STDERR to non-blocking 
		stream_set_blocking($this->pipes[self::STDOUT], 0);
		stream_set_blocking($this->pipes[self::STDERR], 0);
			
		$processInfo = proc_get_status($this->process);
		$this->pid = $processInfo['pid'];
		
		if($processInfo['running']==false) {
			throw new Exception("unable to create FFmpeg process: $commandString");
		}
		
		return $processInfo;
	}
	
	//See if the command is still active
	public function isActive () {
		$processInfo = proc_get_status($this->process);
		if ( $processInfo['running'] ) {
			return true;
		} else {
			$this->exitcode = $processInfo['exitcode'];
			return false;
		}	
	}
	
	//Close the process
	public function stop () {
		if( $this->isActive() ) {
			fclose($this->pipes[self::STDIN]);
			fclose($this->pipes[self::STDOUT]);
			fclose($this->pipes[self::STDERR]);
			
			proc_terminate($this->process); // close seems to be hanging for some reason
			$this->process = null;
			
			return true;
		} else {
			// clear pipes
			$this->listen(self::STDOUT);
			$this->listen(self::STDERR);
			$this->process = null;
			
			return false; // didn't close, flushed
		}
		
	}

	
	public function getStatus() {
		$data = array();
		
		$buffer = $this->fillBuffer(self::STDERR);
		
		// TODO this maybe should just use probe on input -- might be expensive if never found
		if ( !$this->duration ) {
			if ( preg_match("/Duration: ([\d:\.]*)/", implode('', $buffer), $m) ) {
				list($h, $m, $s) = explode(':',$m[1]); // get time info
				$this->duration = (int) ($h*360 + $m*60 + $s);
			}
		};
		
		$lastLine = array_pop($buffer);
			
		if ( !$this->isActive() ) {
			
			// if exitcode > 0 means there's an error, otherwise set complete			
			if ( $this->exitcode > 0 ) {
				throw new Exception("Encoding failed, returned non-zero exitcode: " . $this->exitcode);
			}
			
			$data['message'] = "complete. lastline: " . $lastLine;
			$data['exitcode'] = $this->exitcode;
			
			return $data;	
		}
		
		// else dump status
		if ( preg_match("/frame=(\W*)(\d*)/"      , $lastLine, $m) ) $data['frame']   = $m[2];
		if ( preg_match("/fps=(\W*)(\d*)/"        , $lastLine, $m) ) $data['fps']     = $m[2];
		if ( preg_match("/q=(\W*)(\d*)/"          , $lastLine, $m) ) $data['q']       = $m[2];
		if ( preg_match("/size=(\W*)(\d*)/"       , $lastLine, $m) ) $data['size']    = $m[2];
		if ( preg_match("/bitrate=(\W*)([\d\.]*)/", $lastLine, $m) ) $data['bitrate'] = $m[2];
		if ( preg_match("/time=([\d:\.]*)/"       , $lastLine, $m) ) {
			list($h, $m, $s) = explode(':',$m[1]); // get time info
			$data['time'] = $h*360 + $m*60 + $s; // already deals with fraction.  yay php!
		}
		
		if( empty($data) ) {
			$data['message'] = $lastLine;
		} else {
			if ($this->duration ) {
				$this->progress = $data['time'] / max($this->duration, 0.01);
				$data['percent'] = (int) ($this->progress * 100);
			} else {
				$data['percent'] = '??';
			}
			
			$data['message'] = "{$data['percent']}%, frame: {$data['frame']}, fps: {$data['fps']}, bitrate: {$data['bitrate']}kbps, time: {$data['time']}s of {$this->duration}s";
		}
		
		return $data;
	}
	
	// TODO change this to parse each line in the buffer rather than just deal with last line
	private function fillBuffer ( $pipeNum ) {
		$buffer = array();
		
		// array due to particularities in stream_select
		$pipes = array($this->pipes[$pipeNum]);
		
		if ( feof($pipes[0]) ) {
			return false;
		}
		
		// TODO allow setting timeout value (1 second) as config option?
		$ready= stream_select($pipes, $write = null, $ex = null, 1, 0);
		
		if ($ready === false) {
			//should never happen - something died
			throw new Exception("FFmpeg Job Error: thread ready was false");
		} elseif ($ready === 0 ) { 
			return $buffer; // will be empty
		}
		
		
		// TODO supposedly you shouldn't use unread bytes, but this is what works on Windows
		// can't get stream metadata until read at least once
		$status = array('unread_bytes' => 1);
		$read = true;
		while ( $status['unread_bytes'] > 0 ) {
			//$read = fgets($pipes[0], self::READ_LENGTH);
			$read = fread($pipes[0], self::READ_LENGTH);
			if ($read !== false) {
				$buffer[] = trim($read);
			}
			
			$status = stream_get_meta_data($pipes[0]);
		}
		
		return $buffer;
	}
	
}

?>