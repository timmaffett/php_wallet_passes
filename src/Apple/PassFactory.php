<?php

namespace Chiiya\LaravelPasses\Apple;

use Chiiya\LaravelPasses\Apple\Components\Image;
use Chiiya\LaravelPasses\Apple\Enumerators\ImageType;
use Chiiya\LaravelPasses\Apple\Passes\BoardingPass;
use Chiiya\LaravelPasses\Apple\Passes\Coupon;
use Chiiya\LaravelPasses\Apple\Passes\EventTicket;
use Chiiya\LaravelPasses\Apple\Passes\GenericPass;
use Chiiya\LaravelPasses\Apple\Passes\Pass;
use Chiiya\LaravelPasses\Apple\Passes\StoreCard;
use Chiiya\LaravelPasses\Exceptions\ValidationException;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileObject;
use ZipArchive;

class PassFactory
{
    /**
     * Pass file extension.
     */
    public const PASS_EXTENSION = '.pkpass';

    /**
     * Localization directory extension.
     */
    public const LOCALIZATION_EXTENSION = '.lproj';

    /**
     * Localization strings file name.
     */
    public const STRINGS_FILENAME = 'pass.strings';

    /**
     * Manifest file name.
     */
    public const MANIFEST_FILENAME = 'manifest.json';

    /**
     * Temporary directory for creating the archive.
     */
    protected string $tempDir;

    /**
     * The output path.
     */
    protected ?string $output;

    /**
     * Path to the P12 certificate file.
     */
    protected ?string $certificate;

    /**
     * Password for the P12 certificate file.
     */
    protected ?string $password;

    /**
     * Path to the WWDR certificate file.
     */
    protected ?string $wwdr;

    /**
     * Skip signing the .pkpass package.
     */
    protected bool $skipSignature = false;

    /**
     * Allowed images for each pass type.
     */
    protected array $allowedImages = [
        BoardingPass::class => [ImageType::LOGO, ImageType::ICON, ImageType::FOOTER],
        Coupon::class => [ImageType::LOGO, ImageType::ICON, ImageType::STRIP],
        EventTicket::class => [
            ImageType::LOGO,
            ImageType::ICON,
            ImageType::STRIP,
            ImageType::BACKGROUND,
            ImageType::THUMBNAIL,
        ],
        GenericPass::class => [ImageType::LOGO, ImageType::ICON, ImageType::THUMBNAIL],
        StoreCard::class => [ImageType::LOGO, ImageType::ICON, ImageType::STRIP],
    ];

    public function __construct()
    {
        $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR;
    }

    public function setTempDir(string $tempDir): self
    {
        $this->tempDir = rtrim($tempDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return $this;
    }

    public function setOutput(string $output): self
    {
        $this->output = rtrim($output, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return $this;
    }

    public function setCertificate(string $certificate): self
    {
        $this->certificate = $certificate;

        return $this;
    }

    public function setPassword(string $password): self
    {
        $this->password = $password;

        return $this;
    }

    public function setWwdr(string $wwdr): self
    {
        $this->wwdr = $wwdr;

        return $this;
    }

    public function setSkipSignature(bool $skipSignature): self
    {
        $this->skipSignature = $skipSignature;

        return $this;
    }

    /**
     * Create and archive the .pkpass file.
     */
    public function create(Pass $pass, ?string $name = null): string
    {
        $this->validate($pass);
        $dir = $this->createTempDirectory($pass);
        $this->serializePass($pass, $dir);
        $this->copyImages($pass, $dir);
        $this->createLocalizations($pass, $dir);
        $this->createManifest($dir);
        $this->sign($dir);
        $filename = $this->output.($name ?? $pass->serialNumber).self::PASS_EXTENSION;
        $this->zip($dir, $filename);
        $this->deleteDirectory($dir);

        return new SplFileObject($filename);
    }

    /**
     * Recursively delete a directory.
     */
    public function deleteDirectory(string $directory): void
    {
        $items = new FilesystemIterator($directory);
        foreach ($items as $item) {
            if ($item->isDir() && ! $item->isLink()) {
                $this->deleteDirectory($item->getPathname());
            } else {
                $this->delete($item->getPathname());
            }
        }
        @rmdir($directory);
    }

    /**
     * Delete the file at a given path.
     */
    public function delete(string $path): void
    {
        @unlink($path);
    }

    /**
     * Validate the pass.
     */
    protected function validate(Pass $pass): void
    {
        $errors = $this->validateImages($pass);

        if (count($errors) > 0) {
            throw new ValidationException('Invalid pass', $errors);
        }
    }

    /**
     * Validate that all images are correct.
     */
    protected function validateImages(Pass $pass): array
    {
        $class = $pass::class;
        $hasIcon = false;
        $errors = [];
        foreach ($pass->getImages() as $image) {
            $name = $this->getImageName($image);
            if ($this->normalizeName($name) === 'icon') {
                $hasIcon = true;
            }
            if (mb_strtolower($image->getExtension()) !== 'png') {
                $errors[] = $image->getFilename().': expected .png extension, found .'.$image->getExtension();
            }
            if (! $this->isValidImage($name, $class)) {
                $errors[] = 'Invalid image type `'.$name.'` for pass type `'.$class.'`.';
            }
        }
        if ($pass instanceof EventTicket) {
            $errors = $this->validateEventTicket($pass, $errors);
        }
        if (! $hasIcon) {
            $errors[] = 'The pass must have an icon image.';
        }

        return $errors;
    }

    /**
     * For event tickets, a background or thumbnail image may only be specified when NO strip image
     * has been added.
     */
    protected function validateEventTicket(Pass $pass, array $errors): array
    {
        $hasStrip = count(
            array_filter($pass->getImages(), fn (Image $image) => $this->normalizeName(
                $this->getImageName($image)
            ) === ImageType::STRIP)
        ) > 0;
        $hasThumbnailOrBackground = count(array_filter($pass->getImages(), function (Image $image) {
            $name = $this->normalizeName($this->getImageName($image));

            return $name === ImageType::THUMBNAIL || $name === ImageType::BACKGROUND;
        })) > 0;
        if ($hasStrip && $hasThumbnailOrBackground) {
            $errors[] = 'When specifying a strip image, no background image or thumbnail may be specified.';
        }

        return $errors;
    }

    /**
     * Check whether a provided image is a valid asset for the given pass type.
     */
    protected function isValidImage(string $name, string $class): bool
    {
        $allowed = $this->allowedImages[$class] ?? [];

        return in_array($name, $allowed, true)
            || in_array($this->normalizeName($name), $allowed, true);
    }

    /**
     * Normalize the image name. For e.g. icons should be either `icon` or `icon@2x`.
     */
    protected function normalizeName(string $name): string
    {
        return str_replace(['@2x', '@3x'], '', $name);
    }

    /**
     * Create the temporary directory.
     */
    protected function createTempDirectory(Pass $pass): string
    {
        $dir = $this->tempDir.$pass->serialNumber.DIRECTORY_SEPARATOR;

        if (! @mkdir($dir, 0755) && ! is_dir($dir)) {
            throw new RuntimeException(sprintf('Directory "%s" could not be created', $dir));
        }

        return $dir;
    }

    /**
     * Serialize the pass and create pass.json file.
     */
    protected function serializePass(Pass $pass, string $dir): void
    {
        file_put_contents($dir.'pass.json', json_encode($pass, JSON_PRETTY_PRINT));
    }

    /**
     * Copy all images into the temporary directory.
     */
    protected function copyImages(Pass $pass, string $dir): void
    {
        foreach ($pass->getImages() as $image) {
            $filename = $dir.$this->getImageName($image).'.'.$image->getExtension();
            copy($image->getPathname(), $filename);
        }
    }

    protected function getImageName(Image $image): string
    {
        if ($image->getName() !== null) {
            return $image->getScale() > 1 ? $image->getName().'@'.$image->getScale().'x' : $image->getName();
        }

        return $image->getBasename('.'.$image->getExtension());
    }

    /**
     * Create directory and files for each localization entry.
     */
    protected function createLocalizations(Pass $pass, string $dir): void
    {
        foreach ($pass->getLocalizations() as $localization) {
            $localizationDir = $dir.$localization->language.self::LOCALIZATION_EXTENSION;
            if (! mkdir($localizationDir, 0755) && ! is_dir($localizationDir)) {
                throw new RuntimeException(sprintf('Directory "%s" could not be created', $dir));
            }
            $strings = '';
            foreach ($localization->strings as $key => $value) {
                $strings .= '"'.addslashes($key).'" = "'.addslashes($value).'";'.PHP_EOL;
            }
            file_put_contents($localizationDir.self::STRINGS_FILENAME, $strings);

            foreach ($localization->images as $image) {
                $filename = $localizationDir.($image->getName() ?? $image->getFilename());
                copy($image->getPathname(), $filename);
            }
        }
    }

    /**
     * Create the manifest file.
     */
    protected function createManifest(string $dir): void
    {
        $manifest = [];
        $files = new FilesystemIterator($dir);
        foreach ($files as $file) {
            if ($file->isFile()) {
                $path = realpath($file);
                $relative = str_replace($dir, '', $file->getPathname());
                $manifest[$relative] = sha1_file($path);
            }
        }
        file_put_contents($dir.self::MANIFEST_FILENAME, json_encode($manifest, JSON_PRETTY_PRINT));
    }

    /**
     * Sign the pass.
     */
    protected function sign(string $dir): void
    {
        if ($this->skipSignature) {
            return;
        }

        if (! $p12 = file_get_contents($this->certificate)) {
            throw new RuntimeException(sprintf('The certificate at "%s" could not be read', $this->certificate));
        }

        if (! file_exists($this->wwdr)) {
            throw new RuntimeException(sprintf('The WWDR certificate at "%s" could not be read', $this->wwdr));
        }

        $certs = [];

        if (! openssl_pkcs12_read($p12, $certs, $this->password)) {
            throw new RuntimeException(sprintf('Invalid certificate file: "%s"', $this->certificate));
        }

        $certData = openssl_x509_read($certs['cert']);
        $privateKey = openssl_pkey_get_private($certs['pkey'], $this->password);
        $signatureFile = $dir.'signature';

        openssl_pkcs7_sign(
            $dir.self::MANIFEST_FILENAME,
            $signatureFile,
            $certData,
            $privateKey,
            [],
            PKCS7_BINARY|PKCS7_DETACHED,
            $this->wwdr
        );

        $signature = file_get_contents($signatureFile);
        $signature = $this->convertPEMtoDER($signature);
        file_put_contents($signatureFile, $signature);
    }

    /**
     * Converts PKCS7 PEM to PKCS7 DER
     * Parameter: string, holding PKCS7 PEM, binary, detached
     * Return: string, PKCS7 DER.
     */
    protected function convertPEMtoDER(string $signature): string
    {
        $begin = 'filename="smime.p7s"';
        $end = '------';
        $signature = mb_substr($signature, mb_strpos($signature, $begin) + mb_strlen($begin));
        $signature = mb_substr($signature, 0, mb_strpos($signature, $end));
        $signature = trim($signature);

        return base64_decode($signature, true);
    }

    /**
     * Create zip archive.
     */
    protected function zip(string $source, string $path): void
    {
        $zip = new ZipArchive();
        if (! $zip->open($path, ZipArchive::CREATE|ZipArchive::OVERWRITE)) {
            throw new RuntimeException(sprintf('Could not create ZIP file at "%s"', $path));
        }

        /** @var RecursiveDirectoryIterator|RecursiveIteratorIterator $iterator */
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        while ($iterator->valid()) {
            if ($iterator->isDir()) {
                $zip->addEmptyDir($iterator->getSubPathname());
            } elseif ($iterator->isFile()) {
                $zip->addFromString($iterator->getSubPathname(), file_get_contents($iterator->key()));
            }
            $iterator->next();
        }

        $zip->close();
    }
}