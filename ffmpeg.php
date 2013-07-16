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

	const READ_LENGTH = 1024;
	const STDIN = 0;
	const STDOUT = 1;
	const STDERR = 2;
	// change if necessary
	public static $FFMPEG_PATH = 'ffmpeg';

	public $inputFile;
	
	public $inputFilePath;
	public $outputFilePath;
	public $recipe;
	public $duration;
	
	public $progress;
	
	private $pid;
	private $exitcode;
	
	private $process=0;
	private $pipes;
	
	

	function __construct( $inputFile, $outputFile, $recipe ) {
		
		// TODO check for security issues with filenames?  file_exists and ffmpeg should handle...
		
		if ( !file_exists($inputFile) ) {
			throw new Exception("Input file does not exist");
		} else {
			$this->inputFilePath = $inputFile;
		}
		
		// TODO allow setting of output file to same as input directory, or default directory
		// TODO allow overriding output extension based on recipe
		
		// TODO this only allows for filesystem output, not RTP/HTTP etc...
		$outputPathInfo = pathinfo($outputFile);
		if ( !file_exists($outputPathInfo['dirname']) ) {
			throw new Exception("Output directory does not exist");
		} else {
			$this->outputFilePath = $outputFile;
		}
		
		if ( is_a($recipe, 'FFpreset') ) {
			$this->recipe = $recipe;
		} else {
			$this->recipe = new FFpreset($recipe);
		}
		
	}
	
	public function start () {
		
		$commandString = implode(' ', array(
			self::$FFMPEG_PATH,
			'-i', $this->inputFilePath,
			'-nostdin', // no need to interact with process
			$this->recipe->asArgumentsString(),
			$this->outputFilePath
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
		stream_set_blocking ($this->pipes[self::STDOUT], 0);
		stream_set_blocking ($this->pipes[self::STDERR], 0);
		
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
		
		$lastLine = array_pop($this->fillBuffer(self::STDERR));

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
			if ($this->duration ) $data['percent'] = (int) (100*$data['time'] / max($this->duration, 0.01));
			
			$data['message'] = "{$data['percent']}% {$data['frame']}, fps: {$data['fps']}, bitrate: {$data['bitrate']}, time: {$data['time']} of {$this->duration}";
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
		
		$ready= stream_select($pipes, $write = null, $ex = null, 1, 0);
		
		if ($ready === false) {
			//should never happen - something died
			throw new Exception("FFmpeg Job Error: thread ready was false");
		} elseif ($ready === 0 ) { 
			return $buffer; // will be empty
		}
		
		$buffer[] = fgets($pipes[0], self::READ_LENGTH);
		$status = stream_get_meta_data($pipes[0]);
		while ( $status['unread_bytes'] > 0 ) {
			$read = fgets($pipes[0], self::READ_LENGTH);
			$buffer[] = trim($read);
//print "adding to buffer: " . trim($read) . "\n";
			$status = stream_get_meta_data($pipes[0]);
		}
		
		return $buffer;
	}
	
}

/*
EXAMPLE USAGE

// path to input file
$inputFile = 'myinputfile.webm';
$outputFile = 'myoutputfile.webm';

// recipe
$recipe = FFpreset::fromFile('libvpx-360p.ffpreset');


$recipe

// set maximum width/height
// $recipe->constrainSize(640, 360); now set by preset

// create job
$job = new FFmpeg($inputFile, $outputFile, $recipe);

$job->start();

print "\n\nSTART\n\n";

while ( $job->isActive() ) {
	$data = $job->getStatus();
	print var_export($data) . "\n";
	sleep(1);
}

print "done!\n";

// or create thumb, using filename as recipe input
$job = new FFmpeg($inputFile, 'thumb.jpg', 'thumb.ffpreset');

// don't display progress, just pause execution until done.
$startTime = time();
print "start: $startTime\n";
$job->start();
// hold execution of script until complete
while ( $job->isActive() ) usleep(100);
$deltaTime = (time() - $startTime) / 1000;
print "complete in $deltaTime s\n";

*/

?>