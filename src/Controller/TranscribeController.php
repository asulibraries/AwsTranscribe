<?php

namespace App\Controller;

use Aws\TranscribeService\TranscribeServiceClient;
use Aws\TranscribeService\Exception\TranscribeServiceException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpClient\Response\CurlResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

/**
 * Transcribe Controller.
 */
class TranscribeController {

  /**
   * @var \Psr\Log\LoggerInterface
   *   The logger.
   */
  protected $log;

  /**
   * @var string
   *   The s3 bucket.
   */
  protected $s3Bucket;

  /**
   * @var string
   *   The file root.
   */
  protected $fileRoot;

  /**
   * @var string
   *   S3 path to PRISM private dir.
   */
  protected $prism_private_path;

  /**
   * @var string
   *   S3 path to KEEP private dir.
   */
  protected $keep_private_path;

  /**
   * @var Symfony\Contracts\HttpClient\HttpClientInterface
   *   The http client.
   */
  private $client;

  /**
   * Controller constructor.
   *
   * @param \Psr\Log\LoggerInterface $log
   *   The logger.
   * @param string $keep_private_path
   *   The KEEP private path.
   * @param string $prism_private_path
   *   The PRISM private path.
   * @param string $s3Bucket
   *   The s3 bucket
   */
  public function __construct(
    LoggerInterface $log,
    string $keep_private_path,
    string $prism_private_path,
    string $s3Bucket,
    string $fileRoot,
    HttpClientInterface $client
  ) {
    $this->log = $log;
    $this->keep_private_path = $keep_private_path;
    $this->prism_private_path = $prism_private_path;
    $this->s3Bucket = $s3Bucket;
    $this->fileRoot = $fileRoot;
    $this->client = $client;
  }

  /**
   * Index.
   */
  public function index(): Response {
    return new Response('<html><body>AWS Transcribe microservice is up and running!</body></html');
  }

  /**
   * Random number function.
   */
  public function number(): Response {
    $number = random_int(0, 100);
    return new Response('<html><body>' . $number . '</body></html>');
  }

  /**
   * Get Transcriptions method.
   */
  public function getTranscriptions(): Response {
    $client = new TranscribeServiceClient([
      'version' => 'latest',
      'region' => 'us-west-2',
      'profile' => 'transcribe',
    ]);
    $result = $client->listTranscriptionJobs();
    return new Response($result);
  }

  /**
   * Get Transcription method.
   */
  public function getTranscription(string $job_name): Response {
    $result = $this->getTranscriptJobInfo($job_name);
    return new Response($result);
  }

  /**
   * Get json output.
   */
  public function getTranscriptJson(string $job_name): Response {
    $result = $this->getTranscriptJobInfo($job_name);
    $json_url = $result['TranscriptionJob']['Transcript']['TranscriptFileUri'];
    $json = $this->client->request('GET', $json_url);
    return new Response($json->getContent());
  }

  /**
   * Get the transcription job info.
   */
  private function getTranscriptJobInfo(string $job_name) {
    $client = new TranscribeServiceClient([
      'version' => 'latest',
      'region' => 'us-west-2',
      'profile' => 'transcribe',
    ]);
    $result = $client->getTranscriptionJob([
      'TranscriptionJobName' => $job_name,
    ]);
    return $result;
  }

  /**
   * Start the derivative job from Drupal.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   * @return \Symfony\Component\HttpFoundation\Response|\Symfony\Component\HttpClient\Response\CurlResponse
   */
  public function startJobFromDrupal(Request $request) {
    $this->log = new Logger('islandora_aws_transcribe');
    $this->log->pushHandler(new StreamHandler('/var/log/islandora/aws_transcribe.log', Logger::DEBUG));
    $this->log->info('Caption request.');
    $resource_url = $request->headers->get('Apix-Ldp-Resource');
    $this->log->info("Resource to transcribe url is {$resource_url}");
    $bucket_name = $this->s3Bucket;
    if (str_contains($resource_url, 'cloudfront.net')) {
      // Cloudfront provides the information we need to find the resource.
      $path = urldecode(parse_url($resource_url, PHP_URL_PATH));
      $mediaFileUri = "s3://$bucket_name" . $path;
      $this->log->info("media file URI is " . $mediaFileUri);
    } else {
      // The resource is located in a private directory
      if (preg_match('/keep(-\w+)?\.lib\.asu\.edu/', $resource_url)) {
        $path = $this->keep_private_path;
      } else if (preg_match('/prism(-\w+)?\.lib\.asu\.edu/', $resource_url)) {
        $path = $this->prism_private_path;
      }
      $mediaFileUri = "s3://$bucket_name/$path" . urldecode(parse_url($resource_url, PHP_URL_PATH));
    }
    // Digest the path to create a reproducable unique identifier for finding
    // completed transcriptions or existing jobs.
    $digest = md5($mediaFileUri);
    $this->log->info($mediaFileUri ." digest is " . $digest);
    $filesystem = new Filesystem();
    $finder = new Finder();
    $infile = $this->fileRoot . "/" . "infiles/" . $digest . "_infile.json";
    $outfile = $this->fileRoot . "/" . "outfiles/" . $digest . "_outfile.vtt";

    // Return existing captions if they exist.
    if ($filesystem->exists($outfile)) {
      $files = $finder->files()->in($this->fileRoot . "/" . "outfiles")->name($digest . "_outfile.vtt");
      foreach ($files as $file) {
        // Don't send empty files.
        if (filesize($file->getRealPath()) == 0) {
          return new Response("Transcription was empty.", 200, [
            "Content-Type" => "text/plain"
          ]);
	}
        $destinationUri = $request->headers->get('X-Islandora-Destination');
	try {
         // Send caption file to Drupal.
         $headers = [];
         $headers['Content-Location'] = $request->headers->get('X-Islandora-FileUploadUri');
         $headers['Content-Type'] = "text/plain";
         $headers['Authorization'] = $request->headers->get('Authorization');
	 $this->log->info("Sending {$file->getRealPath()} to {$destinationUri}");
	 $this->log->debug(print_r($headers, TRUE));
         $drupal_response = $this->client->request(
                'PUT',
                $destinationUri,
                [
                    'headers' => $headers,
                    'body' => $file->getContents()
                ],
         );
	 $this->log->debug($drupal_response->getStatusCode());
	 $drupal_put_out = $drupal_response->getContent();

	 // Report back to Alpaca.
	 return new Response($file->getContents(), 200, [
            "Content-Type" => "text/plain"
          ]);
	} catch (\Exception $e){ 
           $this->log->error("Exception:", ['exception' => $e]);
            return new Response($e->getMessage(), 500);
	}
      }
    }

    // No existing caption; tell AWSTranscribe to make one.
    $transcribeClient = new TranscribeServiceClient([
      'version' => 'latest',
      'region' => 'us-west-2',
      'profile' => 'transcribe',
    ]);

    if (!$filesystem->exists($infile)) {
      $media_job = [
        'TranscriptionJobName' => $digest,
        'Media' => [
          'MediaFileUri' => $mediaFileUri,
        ],
	// Could also use IdentifyMultipleLanguages or supply a list of
	// languages with LanguageOptions.
	// See https://docs.aws.amazon.com/transcribe/latest/APIReference/API_StartTranscriptionJob.html.
        'IdentifyLanguage' => true,
      ];
      $this->log->debug("Job configuration: " . json_encode($media_job));

      // Check if a job is already in progress.
      try {
        $xstatus = $transcribeClient->getTranscriptionJob([
          'TranscriptionJobName' => $digest,
        ]);
      }
      catch (TranscribeServiceException $e) {
	$this->log->debug("Transcription job doesn't exist yet: " . $e->getAwsErrorMessage());
        $result = $transcribeClient->startTranscriptionJob($media_job);
        $this->log->debug("Start Transcription job response: " . print_r($result, TRUE));
      }
      $status = [];

      // Wait for the job to complete.
      while (TRUE) {
        $status = $transcribeClient->getTranscriptionJob([
          'TranscriptionJobName' => $digest,
        ]);
        $this->log->info($status->get('TranscriptionJob')['TranscriptionJobStatus']);

        if ($status->get('TranscriptionJob')['TranscriptionJobStatus'] == 'COMPLETED') {
          break;
        }
        if ($status->get('TranscriptionJob')['TranscriptionJobStatus'] == 'FAILED') {
          $this->log->error("RuntimeException:", ['exception' => "AWS Transcription JOB Failed"]);
          $this->log->error($status->get('TranscriptionJob')['FailureReason']);
          return new Response($status->get('TranscriptionJob')['FailureReason'], 500);
        }

	// Give it more time to process before chcking again.
        sleep(5);
      }
    }

    // Job completed successfully; return it.
    try {
      $this->log->info("AWS transcription job {$digest} completed");
      if (!isset($status)) {
        $status = $transcribeClient->getTranscriptionJob([
          'TranscriptionJobName' => $digest,
        ]);
      }
      $transcript_url = $status->get('TranscriptionJob')['Transcript']['TranscriptFileUri'];
      $json = $this->client->request('GET', $transcript_url);
      $json_body = $json->getContent();
      try {
        $filesystem->dumpFile($infile, $json_body);
      }
      catch (\Exception $exception) {
        $this->log->error("Could not write json to file");
        $this->log->error($exception);
      }

      $this->log->debug("Converting transcript json file {$infile} to WebVTT {$outfile}");
      $py_command = "/usr/bin/python3 /var/www/html/AwsTranscribe/awstosrt.py " . $infile . " " . $outfile;
      try {
        $py_command = escapeshellcmd($py_command);
        $output = shell_exec($py_command); //, $output, $retval);
        $this->log->debug("Python script returned with output: \n" . print_r($output, TRUE));
        $files = $finder->files()->in($this->fileRoot . "/" . "outfiles")->name($digest . "_outfile.vtt");
        foreach ($files as $file) {
          // Don't send empty files.
          if (filesize($file->getRealPath()) == 0) {
            return new Response("Transcription was empty.", 200, [
              "Content-Type" => "text/plain"
            ]);
          }
          // Send the file to Drupal.
          $headers = [];
          $headers['Content-Location'] = $request->headers->get('X-Islandora-FileUploadUri');
          $headers['Content-Type'] = "text/plain";
          $headers['Authorization'] = $request->headers->get('Authorization');
          $this->log->info("sending to " . $destinationUri);
          $this->log->info(print_r($headers, TRUE));
          $drupal_response = $this->client->request(
                'PUT',
                $destinationUri,
                [
                    'headers' => $headers,
                    'body' => $file->getContents()
                ],
          );
	  $this->log->debug($drupal_response->getStatusCode());

	  // Report back to Alpaca.
          $drupal_put_out = $drupal_response->getContent();
          return new Response(
            $file->getContents(),
            200,
            [
              "Content-Type" => "text/plain"
            ]
          );
        }
      }
      catch (\RuntimeException $e) {
        $this->log->error("RuntimeException:", ['exception' => $e]);
        $this->log->error("Failed executing python script");
        return new Response($e->getMessage(), 500);
      }
    }
    catch (\RuntimeException $e) {
      $this->log->error("RuntimeException:", ['exception' => $e]);
      $this->log->error("Failed to start and  get the transcription job results and process them");
      return new Response($e->getMessage(), 500);
    }

  }

}
