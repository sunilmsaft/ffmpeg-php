In progress wrapper around ffmpeg, for use as a background process.  provides status updates, and allows setting parameters via ffpreset files / JSON / arrays.

EXAMPLE USAGE

		// path to input file
		$inputFile = 'myinputfile.webm';
		$outputFile = 'myoutputfile.webm';

		// recipe
		$recipe = FFmpegRecipe::fromFile('libvpx-360p.ffpreset');

		// set maximum width/height
		$recipe->constrainSize(640, 360);

		// create job
		$job = new FFmpegJob($inputFile, $outputFile, $recipe);

		$job->start();

		print "\n\nSTART\n\n";

		while ( $job->isActive() ) {
			$data = $job->getStatus();
			print var_export($data) . "\n";
			sleep(1);
		}

		print "done!\n";