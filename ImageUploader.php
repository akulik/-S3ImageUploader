<?php

namespace App\Component\Amazon;

use Imagick;
use Exception;
use Phalcon\Di;
use Aws\S3\S3Client;
use App\Uuid\Uuid;
use Phalcon\Http\Request\File;
use Phalcon\Mvc\User\Component;
use App\Frontend\Models\Image;
use Aws\S3\Exception\S3Exception;
use Psr\Log\LoggerInterface;

/**
 * Class ImageUploader
 *
 * @package App\Component\Amazon
 */
class ImageUploader extends Component
{
    /**
     * @var S3Client
     */
    private $client;

    /**
     * PSR-3 compatible logger object
     *
     * @link http://www.php-fig.org/psr/psr-3/
     * @var  object
     * @see  setLogger()
     */
    protected $logger;

    /**
     * ImageUploader constructor.
     */
    public function __construct()
    {
        $this->client = new S3Client([
            'version' => 'latest',
            'region' => $this->config->s3->region,
            'credentials' => $this->config->s3->credentials->toArray(),
        ]);
    }

    /**
     * @param $path
     * @param $key
     * @return bool|mixed|null
     */
    public function nativeUpload($path, $key)
    {
        return $this->uploadFile($key, $path);
    }

    /**
     * @param File $file
     * @param Image $image
     * @return bool
     */
    public function upload(File $file, Image $image)
    {
        if ($this->isInvalidBySize($file)) {
            return false;
        }

        $main_key = Uuid::v4();
        $main_file = $this->getFile($main_key, $file->getExtension());
        $file->moveTo($main_file);
        $this->setAutoRotate($main_file);

        try {
            $image->main = $this->uploadFile($main_key, $main_file);
        } catch (Exception $e) {
            $this->removeFile($main_file);
            return false;
        }

        try {
            $thumbnail_key = Uuid::v4();
            $thumbnail_file = $this->getFile($thumbnail_key, $file->getExtension());
            $this->resizeFile($main_file, $thumbnail_file, 300);
        } catch (Exception $e) {
            $this->removeFile($main_file);
            return false;
        }

        try {
            $image->thumbnail = $this->uploadFile($thumbnail_key, $thumbnail_file);
        } catch (Exception $e) {
            return false;
        }

        $this->updateImageSize($main_file, $image);

        $this->removeFile($main_file);
        $this->removeFile($thumbnail_file);

        return $image->main && $image->thumbnail;
    }


    /**
     * @param string $src
     */
    private function setAutoRotate($src)
    {
        if (!empty($src)) {
            $resource = new Imagick();
            $resource->readImage($src);

            switch ($resource->getImageOrientation()) {
                case Imagick::ORIENTATION_BOTTOMRIGHT:
                    $resource->rotateimage("#000", 180);
                    break;

                case Imagick::ORIENTATION_RIGHTTOP:
                    $resource->rotateimage("#000", 90);
                    break;

                case Imagick::ORIENTATION_LEFTBOTTOM:
                    $resource->rotateimage("#000", -90);
                    break;
            }

            $resource->stripImage();
            $resource->writeImage();
        }
    }

    /**
     * @param File $file
     * @return bool
     */
    private function isInvalidBySize(File $file)
    {
        return $file->getSize() > (1024 * 1024 * 10); // 10 Mb
    }

    /**
     * @param string $key
     * @param string $extension
     * @return string
     */
    private function getFile($key, $extension)
    {
        return PUBLIC_DIR . '/images/chat_image/' . $key . '.' . $extension;
    }

    /**
     * @param string $key
     * @param string $file_path
     * @return bool|mixed|null
     */
    private function uploadFile($key, $file_path)
    {
        try {
            $this->client->deleteObject([
                'Bucket' => $this->config->s3->bucket,
                'Key' => $key,
            ]);

            $result = $this->client->putObject([
                'ACL' => 'public-read',
                'Bucket' => $this->config->s3->bucket,
                'Key' => $key,
                'SourceFile' => $file_path,
            ]);

            $this->client->waitUntil('ObjectExists', [
                'Bucket' => $this->config->s3->bucket,
                'Key' => $key,
            ]);

            if ($result && $result->hasKey('ObjectURL')) {
                return $result->get('ObjectURL');
            }
        } catch (S3Exception $e) {
            $this->log(
                'error',
                $e->getMessage()
            );
        } catch (Exception $e) {
            $this->log(
                'error',
                $e->getMessage()
            );
        }

        return false;
    }

    /**
     * @param string $file_path
     * @param Image $image
     */
    private function updateImageSize($file_path, Image $image)
    {
        $resource = new Imagick();
        $resource->readImage($file_path);

        $image->width = $resource->getImageWidth();
        $image->height = $resource->getImageHeight();
    }

    /**
     * @param string $file
     */
    private function removeFile($file)
    {
        if (is_file($file)) {
            unlink($file);
        }
    }

    /**
     * @param string $from_image
     * @param string $to_image
     * @param int $max_size
     */
    private function resizeFile($from_image, $to_image, $max_size)
    {
        $resource = new Imagick();
        $resource->readImage($from_image);
        $resource->resizeImage(
            $this->getImageHeight($resource, $max_size),
            $this->getImageWidth($resource, $max_size),
            Imagick::FILTER_LANCZOS,
            1
        );
        $resource->setImageFileName($to_image);
        $resource->setImageColorspace(Imagick::COLORSPACE_TRANSPARENT);
        $resource->writeImage();
        $resource->clear();
        $resource->destroy();
    }

    /**
     * @param Imagick $resource
     * @param int $max_size
     * @return int
     */
    private function getImageHeight(Imagick $resource, $max_size)
    {
        $width = $resource->getImageWidth();
        $height = $resource->getImageHeight();

        if ($width >= $height) {
            return $max_size;
        }

        $ratio = round($width / $height, 5);

        return round($max_size * $ratio);
    }

    /**
     * @param Imagick $resource
     * @param int $max_size
     * @return int
     */
    private function getImageWidth(Imagick $resource, $max_size)
    {
        $height = $resource->getImageHeight();
        $width = $resource->getImageWidth();

        if ($height >= $width) {
            return $max_size;
        }

        $ratio = round($height / $width, 5);

        return round($max_size * $ratio);
    }

    /**
     * Sets a logger instance on the object
     *
     * @param LoggerInterface $logger PSR-3 compatible logger object
     *
     * @return null
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Log a message to the $logger object
     *
     * @param string $level   Logging level
     * @param string $message Text to log
     * @param array  $context Additional information
     *
     * @return null
     */
    protected function log($level, $message, array $context = array())
    {
        if ($this->logger) {
            $this->logger->log($level, $message, $context);
        }
    }
}
