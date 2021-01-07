<?php

namespace App\Controller;

use Aws\TranscribeService\TranscribeServiceClient;
use Aws\S3\S3Client;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
   *   The s3 bucket.
   */
  protected $s3Bucket;

  /**
   * @var string
   *   The file root.
   */
  protected $fileRoot;

  /**
   * Controller constructor.
   *
   * @param \Psr\Log\LoggerInterface $log
   *   The logger.
   * @param string $fedoraBaseUrl
   *   The fedora base url.
   * @param string $s3Bucket
   *   The s3 bucket
   */
  public function __construct(
    LoggerInterface $log,
    string $fedoraBaseUrl,
    string $s3Bucket,
    string $fileRoot
  ) {
    $this->log = $log;
    $this->fedoraBaseUrl = $fedoraBaseUrl;
    $this->s3Bucket = $s3Bucket;
    $this->fileRoot = $fileRoot;
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
      'region' => 'us-east-1',
      'profile' => 'default',
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
      'region' => 'us-east-1',
    ]);
    $result = $client->startTranscriptionJob([
      'TranscriptionJobName' => 'test1',
      'Media' => [
        'MediaFileUri' => 's3://fcrepo-filestore/19f6648a2fe6f51d228faccd658f77304fd50a3e',
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
    $client = new Client();
    $json = $client->get($json_url);
    return new Response($json->getBody());
  }

  /**
   * Get the transcription job info.
   */
  private function getTranscriptJobInfo(string $job_name) {
    $client = new TranscribeServiceClient([
      'version' => 'latest',
      'region' => 'us-east-1',
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
   * @return \Symfony\Component\HttpFoundation\Response|\Symfony\Component\HttpFoundation\StreamedResponse
   */
  public function startJobFromDrupal(Request $request) {
    $this->log->info('Caption request.');
    $this->log->info($request->headers->get('Apix-Ldp-Resource'));
    $fedora_url = $request->headers->get('Apix-Ldp-Resource');
    $this->log->info($this->fedoraBaseUrl);
    $url_parts = explode('fedora', $fedora_url);
    // $this->log->info();
    $fedora_uri = $this->fedoraBaseUrl . end($url_parts);
    $client = new Client();
    $fedora_info = $client->get($fedora_uri, ["headers" => ["Want-Digest" => "sha"]]);
    $this->log->info($fedora_info->getBody());
    $this->log->info(print_r($fedora_info->getHeaders(), TRUE));
    $digest = $fedora_info->getHeader('Digest')[0];
    $digest = str_replace('sha=', '', $digest);
    $digest = str_replace('sha%3D', '', $digest);
    $this->log->info($digest);
    $transcribeClient = new TranscribeServiceClient([
      'version' => 'latest',
      'region' => 'us-east-1',
    ]);
    $filesystem = new Filesystem();
    $finder = new Finder();
    $infile = $this->fileRoot . "/" . "infiles/" . $digest . "_infile.json";
    $outfile = $this->fileRoot . "/" . "outfiles/" . $digest . "_outfile.srt";
    $this->log->info($outfile);
    if ($filesystem->exists($outfile)) {
      $this->log->info("Caption file already exists - return it");
      $files = $finder->files()->in($this->fileRoot . "/" . "outfiles")->name($digest . "_outfile.srt");
      foreach ($files as $file) {
        // return new StreamedResponse(
        //   $file->getContents(),
        //   200,
        //   [
        //     "Content-Type" => "text/plain"
        //   ]
        // );
        $response = new StreamedResponse(function () use ($file) {
          $file->getContents();
          flush();
        });
        // $response->setCallback();
        $response->headers->set('Content-Type', 'text/plain');
        // $response->send();
        return $response;
      }
    }
    else {
      exit();
    }

    if (!$filesystem->exists($infile)) {
      $result = $transcribeClient->startTranscriptionJob([
        'TranscriptionJobName' => $digest,
        'Media' => [
          'MediaFileUri' => 's3://fcrepo-filestore/' . $digest,
        ],
        'LanguageCode' => 'en-US',
      ]);
      $this->log->info(print_r($result, TRUE));
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
      $client = new Client();
      $json = $client->get($transcript_url);
      $json_body = $json->getBody();
      try {
        $filesystem->dumpFile($infile, $json_body);
      }
      catch (IOExceptionInterface $exception) {
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
        $files = $finder->files()->in($this->fileRoot . "/" . "outfiles")->name($digest . "_outfile.srt");
        foreach ($files as $file) {
          $response = new StreamedResponse(function () use ($file) {
            $file->getContents();
            flush();
          });
          // $response->setCallback();
          $response->headers->set('Content-Type', 'text/plain');
          // $response->send();
          return $response;
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
