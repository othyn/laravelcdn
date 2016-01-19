<?php

namespace Publiux\laravelcdn\Providers;

use Aws\S3\BatchDelete;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Support\Collection;
use Publiux\laravelcdn\Contracts\CdnHelperInterface;
use Publiux\laravelcdn\Providers\Contracts\ProviderInterface;
use Publiux\laravelcdn\Validators\Contracts\ProviderValidatorInterface;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Class AwsS3Provider
 * Amazon (AWS) S3.
 *
 *
 * @category Driver
 *
 * @property string  $provider_url
 * @property string  $threshold
 * @property string  $version
 * @property string  $region
 * @property string  $credential_key
 * @property string  $credential_secret
 * @property string  $buckets
 * @property string  $acl
 * @property string  $cloudfront
 * @property string  $cloudfront_url
 *
 * @author   Mahmoud Zalt <mahmoud@vinelab.com>
 * @author   Raul Ruiz <publiux@gmail.com>
 */
class AwsS3Provider extends Provider implements ProviderInterface
{
    /**
     * All the configurations needed by this class with the
     * optional configurations default values.
     *
     * @var array
     */
    protected $default = [
        'url'       => null,
        'threshold' => 10,
        'providers' => [
            'aws' => [
                's3' => [
                    'version'    => null,
                    'region'     => null,
                    'buckets'    => null,
                    'acl'        => 'public-read',
                    'cloudfront' => [
                        'use'     => false,
                        'cdn_url' => null,
                    ],
                ],
            ],
        ],
    ];

    /**
     * Required configurations (must exist in the config file).
     *
     * @var array
     */
    protected $rules = ['version', 'region', 'key', 'secret', 'buckets', 'url'];

    /**
     * this array holds the parsed configuration to be used across the class.
     *
     * @var array
     */
    protected $supplier;

    /**
     * @var Instance of Aws\S3\S3Client
     */
    protected $s3_client;

    /**
     * @var Instance of Guzzle\Batch\BatchBuilder
     */
    protected $batch;

    /**
     * @var \Publiux\laravelcdn\Contracts\CdnHelperInterface
     */
    protected $cdn_helper;

    /**
     * @var \Publiux\laravelcdn\Validators\Contracts\ConfigurationsInterface
     */
    protected $configurations;

    /**
     * @var \Publiux\laravelcdn\Validators\Contracts\ProviderValidatorInterface
     */
    protected $provider_validator;

    /**
     * @param \Symfony\Component\Console\Output\ConsoleOutput                     $console
     * @param \Publiux\laravelcdn\Validators\Contracts\ProviderValidatorInterface $provider_validator
     * @param \Publiux\laravelcdn\Contracts\CdnHelperInterface                    $cdn_helper
     */
    public function __construct(
        ConsoleOutput $console,
        ProviderValidatorInterface $provider_validator,
        CdnHelperInterface $cdn_helper
    ) {
        $this->console = $console;
        $this->provider_validator = $provider_validator;
        $this->cdn_helper = $cdn_helper;
    }

    /**
     * Read the configuration and prepare an array with the relevant configurations
     * for the (AWS S3) provider. and return itself.
     *
     * @param $configurations
     *
     * @return $this
     */
    public function init($configurations)
    {
        // merge the received config array with the default configurations array to
        // fill missed keys with null or default values.
        $this->default = array_merge($this->default, $configurations);

        $supplier = [
            'provider_url'   => $this->default['url'],
            'threshold'      => $this->default['threshold'],
            'version'        => $this->default['providers']['aws']['s3']['version'],
            'region'         => $this->default['providers']['aws']['s3']['region'],
            'buckets'        => $this->default['providers']['aws']['s3']['buckets'],
            'acl'            => $this->default['providers']['aws']['s3']['acl'],
            'cloudfront'     => $this->default['providers']['aws']['s3']['cloudfront']['use'],
            'cloudfront_url' => $this->default['providers']['aws']['s3']['cloudfront']['cdn_url'],
        ];

        // check if any required configuration is missed
        $this->provider_validator->validate($supplier, $this->rules);

        $this->supplier = $supplier;

        return $this;
    }

    /**
     * Create an S3 client instance
     * (Note: it will read the credentials form the .env file).
     *
     * @return bool
     */
    public function connect()
    {
        try {
            // Instantiate an S3 client
            $this->setS3Client(new S3Client([
                        'version' => $this->supplier['version'],
                        'region'  => $this->supplier['region'],
                    ]
                )
            );
        } catch (\Exception $e) {
            return false;
        }

        return true;
    }

    /**
     * Upload assets.
     *
     * @param $assets
     *
     * @return bool
     */
    public function upload($assets)
    {
        // connect before uploading
        $connected = $this->connect();

        if (!$connected) {
            return false;
        }

        // user terminal message
        $this->console->writeln('<fg=yellow>Comparing local files and bucket...</fg=yellow>');

        $assets = $this->getFilesAlreadyOnBucket($assets);

        // upload each asset file to the CDN
        if (count($assets) > 0) {

            // Review files before upload if user wishes.
            $review = $this->console->option('review');
            if ($review) {
                $this->console->writeln('<fg=green>The files to be uploaded are....</fg=green>');
                foreach ($assets as $file) {
                    $this->console->writeln('<fg=cyan>'.$file->getRealpath().'</fg=cyan>');
                }

                //Ask the user to confirm that they want to continue the upload.
                if (!$this->console->confirm('Do you wish to continue? [y|N]')) {
                    $this->console->writeln('<fg=red>Upload cancelled.</fg=cyan>');
                    return true;
                }
            }

            $this->console->writeln('<fg=yellow>Upload in progress......</fg=yellow>');
            foreach ($assets as $file) {
                try {
                    $this->console->writeln('<fg=cyan>'.'Uploading file path: '.$file->getRealpath().'</fg=cyan>');
                    $command = $this->s3_client->getCommand('putObject', [

                        // the bucket name
                        'Bucket' => $this->getBucket(),
                        // the path of the file on the server (CDN)
                        'Key' => str_replace('\\', '/', $file->getPathName()),
                        // the path of the path locally
                        'Body' => fopen($file->getRealPath(), 'r'),
                        // the permission of the file

                        'ACL'          => $this->acl,
                        'CacheControl' => $this->default['providers']['aws']['s3']['cache-control'],
                        'MetaData'     => $this->default['providers']['aws']['s3']['metadata'],
                        'Expires'      => $this->default['providers']['aws']['s3']['expires'],
                    ]);
//                var_dump(get_class($command));exit();

                    $this->s3_client->execute($command);
                } catch (S3Exception $e) {
                    $this->console->writeln('<fg=red>'.$e->getMessage().'</fg=red>');

                    return false;
                }
            }

            // user terminal message
            $this->console->writeln('<fg=green>Upload completed successfully.</fg=green>');
        } else {
            // user terminal message
            $this->console->writeln('<fg=yellow>No new files to upload.</fg=yellow>');
        }

        return true;
    }

    /**
     * Empty bucket.
     *
     * @return bool
     */
    public function emptyBucket()
    {

        // connect before uploading
        $connected = $this->connect();

        if (!$connected) {
            return false;
        }

        // user terminal message
        $this->console->writeln('<fg=yellow>Emptying in progress...</fg=yellow>');

        try {

            // Get the contents of the bucket for information purposes
            $contents = $this->s3_client->listObjects([
                'Bucket' => $this->getBucket(),
                'Key'    => '',
            ]);

            // Check if the bucket is already empty
            if (!$contents['Contents']) {
                $this->console->writeln('<fg=green>The bucket '.$this->getBucket().' is already empty.</fg=green>');

                return true;
            }

            // Empty out the bucket
            $empty = BatchDelete::fromListObjects($this->s3_client, [
                'Bucket' => $this->getBucket(),
                'Prefix' => null,
            ]);

            $empty->delete();
        } catch (S3Exception $e) {
            $this->console->writeln('<fg=red>'.$e->getMessage().'</fg=red>');

            return false;
        }

        $this->console->writeln('<fg=green>The bucket '.$this->getBucket().' is now empty.</fg=green>');

        return true;
    }

    /**
     * This function will be called from the CdnFacade class when
     * someone use this {{ Cdn::asset('') }} facade helper.
     *
     * @param $path
     *
     * @return string
     */
    public function urlGenerator($path)
    {
        if ($this->getCloudFront() === true) {
            $url = $this->cdn_helper->parseUrl($this->getCloudFrontUrl());

            return $url['scheme'].'://'.$url['host'].'/'.$path;
        }

        $url = $this->cdn_helper->parseUrl($this->getUrl());

        $bucket = $this->getBucket();
        $bucket = (!empty($bucket)) ? $bucket.'.' : '';

        return $url['scheme'].'://'.$bucket.$url['host'].'/'.$path;
    }

    /**
     * @param $s3_client
     */
    public function setS3Client($s3_client)
    {
        $this->s3_client = $s3_client;
    }

    /**
     * @return string
     */
    public function getUrl()
    {
        return rtrim($this->provider_url, '/').'/';
    }

    /**
     * @return string
     */
    public function getCloudFront()
    {
        if (!is_bool($cloudfront = $this->cloudfront)) {
            return false;
        }

        return $cloudfront;
    }

    /**
     * @return string
     */
    public function getCloudFrontUrl()
    {
        return rtrim($this->cloudfront_url, '/').'/';
    }

    /**
     * @return array
     */
    public function getBucket()
    {
        // this step is very important, "always assign returned array from
        // magical function to a local variable if you need to modify it's
        // state or apply any php function on it." because the returned is
        // a copy of the original variable. this prevent this error:
        // Indirect modification of overloaded property
        // Publiux\laravelcdn\Providers\AwsS3Provider::$buckets has no effect
        $bucket = $this->buckets;

        return rtrim(key($bucket), '/');
    }

    /**
     * @param $attr
     *
     * @return Mix | null
     */
    public function __get($attr)
    {
        return isset($this->supplier[$attr]) ? $this->supplier[$attr] : null;
    }

    /**
     * @param $assets
     *
     * @return mixed
     */
    private function getFilesAlreadyOnBucket($assets)
    {
        $filesOnAWS = new Collection([]);

        $files = $this->s3_client->listObjects([
            'Bucket' => $this->getBucket(),
        ]);

        if (!$files['Contents']) {
            //no files on bucket. lets upload everything found.
            return $assets;
        }

        foreach ($files['Contents'] as $file) {
            $a = ['Key' => $file['Key'], 'LastModified' => $file['LastModified']->getTimestamp(), 'Size' => $file['Size']];
            $filesOnAWS->put($file['Key'], $a);
        }

        $assets->transform(function ($item, $key) use (&$filesOnAWS) {
            $fileOnAWS = $filesOnAWS->get(str_replace('\\', '/', $item->getPathName()));

            //select to upload files that are different in size AND last modified time.
            if (!($item->getMTime() === $fileOnAWS['LastModified']) && !($item->getSize() === $fileOnAWS['Size'])) {
                return $item;
            }
        });

        $assets = $assets->reject(function ($item) {
            return $item === null;
        });

        return $assets;
    }
}
