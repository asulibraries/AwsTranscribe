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
   *   The fedora base url.
   */
  protected $fedoraBaseUrl;
  
  /**
   * @var string
   *   The fedora s3 bucket.
   */
  protected $fedoras3Bucket;

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
   * @var Symfony\Contracts\HttpClient\HttpClientInterface
   *   The http client.
   */
  private $client;

  /**
   * Controller constructor.
   *
   * @param \Psr\Log\LoggerInterface $log
   *   The logger.
   * @param string $fedoraBaseUrl
   *   The fedora base url.
   * @param string $fedoras3Bucket
   *   The fedora s3 bucket
   * @param string $s3Bucket
   *   The s3 bucket
   */
  public function __construct(
    LoggerInterface $log,
    string $fedoraBaseUrl,
    string $fedoras3Bucket,
    string $s3Bucket,
    string $fileRoot,
    HttpClientInterface $client
  ) {
    $this->log = $log;
    $this->fedoraBaseUrl = $fedoraBaseUrl;
    $this->fedoras3Bucket = $fedoras3Bucket;
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
   * Create Transcription job.
   */
  public function createJob(): Response {
    $client = new TranscribeServiceClient([
      'version' => 'latest',
      'region' => 'us-west-2',
      'profile' => 'transcribe',
    ]);
    $result = $client->startTranscriptionJob([
      'TranscriptionJobName' => 'test1',
      'Media' => [
        'MediaFileUri' => 's3://' . $this->fedoras3Bucket . '/19f6648a2fe6f51d228faccd658f77304fd50a3e',
      ],
      'LanguageCode' => 'en-US',
    ]);
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
    $this->log->info('Caption request.');
    $fedora_url = $request->headers->get('Apix-Ldp-Resource');
    $this->log->info("fedora url is " . $fedora_url);
    if (str_contains($fedora_url, 'cloudfront.net')) {
      $this->log->info("its an s3 location");
      $bucket_name = $this->s3Bucket;
      $this->log->info("bucket name is " . $bucket_name);
      $path_re = '/https?:\/\/[^\/]*\/([^?]*)?.*$/m';
      preg_match_all($path_re, $fedora_url, $matches2, PREG_SET_ORDER, 0);
      $path = urldecode($matches2[0][1]);
      $this->log->info("s3 path is " . $path);
      $digest = md5($path);
      $this->log->info("digest is " . $digest);
      $mediaFileUri = 's3://' . $bucket_name . '/' . $path;
      $this->log->info("media file URI is " . $mediaFileUri);
    } else {
      $this->log->info("its a fedora location");
      if (str_contains(parse_url($fedora_url, PHP_URL_HOST), 'keep')) {                                    
        $this->fedoraBaseUrl = "http://fcrepo:8080/fcrepo/rest/asu_ir";                                    
      } else if (str_contains(parse_url($fedora_url, PHP_URL_HOST), 'prism')) {                            
        $this->fedoraBaseUrl = "http://fcrepo:8080/fcrepo/rest/prism";                                     
      }
      $url_parts = explode('fedora', $fedora_url);
      $fedora_uri = $this->fedoraBaseUrl . end($url_parts);
      $this->log->info("fedora uri " . $fedora_uri);
      $fedora_info = $this->client->request('GET', $fedora_uri, ["headers" => ["Want-Digest" => "sha"]]);
      $this->log->info(print_r($fedora_info->getHeaders(), TRUE));
      $digest = $fedora_info->getHeaders()['digest'][0];
      $digest = str_replace('sha=', '', $digest);
      $digest = str_replace('sha%3D', '', $digest);
      $mediaFileUri = 's3://' . $this->fedoras3Bucket . '/' . $digest;
    }
    $filesystem = new Filesystem();
    $finder = new Finder();
    $infile = $this->fileRoot . "/" . "infiles/" . $digest . "_infile.json";
    $outfile = $this->fileRoot . "/" . "outfiles/" . $digest . "_outfile.vtt";
    $this->log->info($outfile);
    if ($filesystem->exists($outfile)) {
      $this->log->info("Caption file already exists - return it");
      $files = $finder->files()->in($this->fileRoot . "/" . "outfiles")->name($digest . "_outfile.vtt");
      foreach ($files as $file) {
         
	$this->log->info("about to put back to drupal");
         $destinationUri = $request->headers->get('X-Islandora-Destination');
	try {
         $headers = [];
         $headers['Content-Location'] = $request->headers->get('X-Islandora-FileUploadUri');
         $headers['Content-Type'] = "text/plain";
         $headers['Authorization'] = $request->headers->get('Authorization');
	 $this->log->info("sending to " . $destinationUri);
	 $this->log->info(print_r($headers, TRUE));
         $response2 = $this->client->request(
                'PUT',
                $destinationUri,
                [
                    'headers' => $headers,
                    'body' => $file->getContents()
                ],
         );
	 $this->log->info($response2->getStatusCode());
	 $drupal_put_out = $response2->getContent();
	 //return $response2;
	 return new Response($file->getContents(), 200, [
            "Content-Type" => "text/plain"
          ]);
	} catch (\Exception $e){ 
           $this->log->error("Exception:", ['exception' => $e]);
            return new Response($e->getMessage(), 500);
	}
      }
    }

    $this->log->info("going to talk to the client now");
    $transcribeClient = new TranscribeServiceClient([
      'version' => 'latest',
      'region' => 'us-west-2',
      'profile' => 'transcribe',
    ]);

    if (!$filesystem->exists($infile)) {
      $this->log->info('media file uri is ' . $mediaFileUri);
      $this->log->info("about to start transcriptionJob");
      $media_job = [
        'TranscriptionJobName' => $digest,
        'Media' => [
          'MediaFileUri' => $mediaFileUri,
        ],
        'LanguageCode' => 'en-US',
      ];
      $this->log->info(print_r($media_job, TRUE));
      try {
        $xstatus = $transcribeClient->getTranscriptionJob([
          'TranscriptionJobName' => $digest,
        ]);
      }
      catch (TranscribeServiceException $e) {
	      $this->log->info("transcript job doesn't exist yet: " . $e->getMessage());
        $result = $transcribeClient->startTranscriptionJob($media_job);
        $this->log->info("after transcription job start");
        $this->log->info(print_r($result, TRUE));
      }
      $status = [];
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

        sleep(5);
      }
    }

    // Return response.
    try {
      // If we made it here the job completed successfully.
      $this->log->info("transcription job completed");
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
      // $fp = fopen($infile, 'w');
      // fwrite($fp, json_encode($json_body));
      // fclose($fp);
      $this->log->info("wrote the transcript json file to disk");
      $py_command = "/usr/bin/python3 /var/www/html/AwsTranscribe/awstosrt.py " . $infile . " " . $outfile;
      try {
        $py_command = escapeshellcmd($py_command);
        $output = shell_exec($py_command); //, $output, $retval);
        $this->log->info("Python script returned with output: \n");
        $this->log->info(print_r($output, TRUE));
        $files = $finder->files()->in($this->fileRoot . "/" . "outfiles")->name($digest . "_outfile.vtt");
        foreach ($files as $file) {
          $headers = [];
          $headers['Content-Location'] = $request->headers->get('X-Islandora-FileUploadUri');
          $headers['Content-Type'] = "text/plain";
          $headers['Authorization'] = $request->headers->get('Authorization');
          $this->log->info("sending to " . $destinationUri);
          $this->log->info(print_r($headers, TRUE));
          $response2 = $this->client->request(
                'PUT',
                $destinationUri,
                [
                    'headers' => $headers,
                    'body' => $file->getContents()
                ],
          );
          $this->log->info($response2->getStatusCode());
          $drupal_put_out = $response2->getContent();
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
