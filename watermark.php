<?php


namespace Citfact\OptimPictures;


use Bitrix\Iblock\ElementPropertyTable;
use Bitrix\Iblock\ElementTable;
use Bitrix\Iblock\PropertyTable;
use Bitrix\Main\Application;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use CFile;
use CIBlockElement;
use Citfact\SiteCore\Core;

class Watermark
{
    /** Временная папка */
    protected static string $baseDir = '/upload/watermark';

    /** Путь к водяному знаку */
    protected string $watermarkPath = '';

    /** Непрозрачность */
    protected int $alphaLevel = 100;

    /** Полный путь к корню сайта */
    protected ?string $documentRoot;

    /** Удаляемые файлы */
    protected array $deleteFilePaths = [];

    protected bool $newFile;

    /** Логи */
    protected array $errorLogs = [];

    protected WebP $webp;

    /**
     * Watermark constructor.
     *
     * @param bool $newFile - создать отдельный файл (false - замена текущего файла)
     */
    public function __construct(bool $newFile = true)
    {
        $this->newFile = $newFile;

        $this->webp = new WebP();

        // Получаем корень сайта
        $this->documentRoot = Application::getDocumentRoot();

        // Получаем водяной знак
        list($this->watermarkPath, $this->alphaLevel) = $this->getWatermarkSettings();

        // Если нет папки для временного хранения обработанных фото, то создадим
        if ($newFile && !file_exists($dir = $this->documentRoot . self::getBaseDir())) {
            mkdir($dir, 0777, true);
        }
    }

    /**
     * Наносит водяной знак на изображение
     *
     * @param string $imagePath - путь до изображения в локальной среде
     * @return string - возвращает путь к изображению в случае успеха
     */
    public function draw(string $imagePath): string
    {
        if (empty($this->watermarkPath)) return $imagePath;

        // Получаем реальный полный путь до изображения
        $fullImagePath = $this->getFileRealPath($imagePath);

        if (empty($fullImagePath)) return $imagePath;

        // Получаем ресурс исходного изображения
        $image = self::getResource($fullImagePath);

        // Получаем ресурс водяного знака
        $watermark = self::getResource($this->watermarkPath);

        if (!$image || !$watermark) {
            self::destroyImages($image,$watermark);
            return $imagePath;
        }

        // Получаем размер изображения
        $image_X = imagesx($image);
        $image_Y = imagesy($image);

        // Если изображение больше водяного знака то создаем новый водяной знак по размерам изображения
        // Эта операция потребляет больше ресурсов поэтому чем меньше водяной знак тем хуже
        if ($tiling = self::tiling($image, $watermark)) {

            // Создаем новое пустое изображение
            $newWatermark = imagecreate($image_X, $image_Y);

            // Заполняем изображение водяным знаком
            foreach ($tiling as $value) {
                $result = imagecopy($newWatermark, $watermark, $value['X'], $value['Y'], 0, 0, $image_X, $image_Y);

                if (!$result) {
                    $this->errorLogs[$imagePath] = error_get_last();
                    self::destroyImages($image,$watermark,$newWatermark);
                    return $imagePath;
                }
            }

            // Чистим память от старого водяного знака и заменяем новым
            self::destroyImages($watermark);
            $watermark = $newWatermark;
        }

        // Накладываем водяной знак
        $result = imagecopy($image, $watermark, 0, 0, 0, 0, $image_X, $image_Y);

        if (!$result) {
            $this->errorLogs[$imagePath] = error_get_last();
            self::destroyImages($image,$watermark);
            return $imagePath;
        }

        $resultPath = $fullImagePath;

        // Если нужно отдельно сохранить изображение то сохраняем в папку
        // Либо заменяем оригинал
        if ($this->newFile) {

            // Создаем полный путь до папки с изображениями
            $dir = implode('/', [$this->documentRoot . self::getBaseDir(),md5(microtime(true))]);

            // Создаем папку, если ее нет
            if (!file_exists($dir)) {
                mkdir($dir, 0777, true);
            }

            // Создаем полный путь до результирующего изображения
            $resultPath = implode('/', [$dir,pathinfo($fullImagePath)['basename']]);

            // Сохраняем
            $result = imagepng($image, $resultPath);

            // Добавляем на удаление
            $this->deleteFilePaths[] = $resultPath;

        } else {

            // Заменяем
            $result = imagepng($image, $fullImagePath);

        }

        // Чистим память
        self::destroyImages($image,$watermark);

        if ($result) {
            return $resultPath;
        } else {
            return $imagePath;
        }
    }

    /**
     * Получает реальный путь к файлу
     *
     * @param string $filepath
     * @return string
     */
    protected function getFileRealPath(string $filepath): string
    {
        // Если файл существует
        if (empty($filepath)) return false;

        // Если изображение находится в CDN
        if ($this->webp->isCloudEnable($filepath)) {

            $fullImagePath = implode('/', [
                $this->documentRoot . self::getBaseDir(),
                md5(microtime(true)),
                pathinfo($filepath)['basename']
            ]);

            CreateImage::getFileFromCloud($filepath, $fullImagePath);

            $this->deleteFilePaths[] = $fullImagePath;

            return $fullImagePath;

        // Если изображение находится в корне сайта и это не папка
        } else if (file_exists($this->documentRoot . $filepath) && !is_dir($this->documentRoot . $filepath)) {

            return $this->documentRoot . $filepath;

        // Если изображение находится в корне сервера и это не папка
        } else if (!is_dir($filepath)){
            return $filepath;
        }

        return false;
    }

    /**
     * Получает квадрат замощения
     * (количество наложения водяного знака по осям X и Y)
     *
     * @param resource $image
     * @param resource $watermark
     * @return array
     */
    protected static function tiling($image, $watermark): array
    {
        $result = [];

        $image_X = imagesx($image);
        $image_Y = imagesy($image);

        $watermark_X = imagesx($watermark);
        $watermark_Y = imagesy($watermark);

        $tiling_X = ceil($image_X / $watermark_X);
        $tiling_Y = ceil($image_Y / $watermark_Y);

        if ($tiling_X <= 1 && $tiling_Y <= 1) return $result;

        for ($Y = 0; $Y < $tiling_Y; $Y++) {
            for ($X = 0; $X < $tiling_X; $X++) {
                $result[] = [
                    'X' => $watermark_X * $X,
                    'Y' => $watermark_Y * $Y,
                ];
            }
        }

        return $result;

    }

    /**
     * Создает изображение с нужной прозрачностью
     *
     * @param string $filepath
     * @param int $alpha
     * @return string
     */
    protected function createImageAlpha(string $filepath, int $alpha): string
    {
        $image = $this->getFileRealPath($filepath);

        $image = self::getResource($image);

        if (empty($image)) return $filepath;

        $image_X = imagesx($image);
        $image_Y = imagesy($image);

        $newImage = imagecreate($image_X, $image_Y);

        $canvas = imagecreatetruecolor($image_X, $image_Y);

        imagefill($canvas ,0,0,
            imagecolorallocatealpha($canvas, 0, 0, 0, 127)
        );

        for ($Y = 0; $Y < $image_Y; $Y++) {
            for ($X = 0; $X < $image_X; $X++) {

                $pixel = imagecolorsforindex($image, imagecolorat($image, $X, $Y));

                if ($pixel['alpha'] != 127) {
                    $pixel['alpha'] = round(($pixel['alpha'] + (127 - $pixel['alpha']) / 101 * $alpha), 2);
                }

                $color = imagecolorallocatealpha($image, $pixel["red"], $pixel["green"], $pixel["blue"],$pixel["alpha"]);

                imagesetpixel($canvas, $X, $Y, $color);
            }
        }

        $result = imagecopy($newImage, $canvas, 0, 0, 0, 0, $image_X, $image_Y);

        if ($result) {

            $dir = implode('/', [$this->documentRoot . self::getBaseDir(),md5(microtime(true))]);

            // Создаем папку, если ее нет
            if (!file_exists($dir)) {
                mkdir($dir, 0777, true);
            }

            // Создаем полный путь до результирующего изображения
            $resultPath = implode('/', [$dir,pathinfo($filepath)['basename']]);

            $result = imagepng($newImage, $resultPath);

            $this->deleteFilePaths[] = $resultPath;

            self::destroyImages($image,$newImage);

            if ($result) return $resultPath;
        }

        self::destroyImages($image,$newImage);
        return $filepath;
    }

    /**
     * Получает источник изображения иначе false
     *
     * @param string $path
     * @return false|resource
     */
    protected static function getResource(string $path)
    {
        $mime = mime_content_type($path);
        $mime = explode('/', $mime);
        $mime = end($mime);

        switch ($mime) {
            case 'png':
                return imagecreatefrompng($path);
            case 'jpg':
            case 'jpeg':
                return imagecreatefromjpeg($path);
            case 'webp':
                return imagecreatefromwebp($path);
            default:
                return false;
        }
    }

    /**
     * Чистит память
     *
     * @param resource ...$images
     */
    protected static function destroyImages(...$images)
    {
        foreach ($images as $image) {
            if (!self::isGDImage($image)) continue;
            imagedestroy($image);
        }
    }

    /**
     * Проверка на GDImage
     * @param $var
     * @return bool
     */
    public static function isGDImage($var) : bool
    {
        return (gettype($var) == "object" && get_class($var) == "GdImage");
    }

    /**
     * Возвращает настройки водяного знака
     *
     * @return array
     */
    protected function getWatermarkSettings(): array
    {
        \CModule::IncludeModule('iblock');

        $core = Core::getInstance();

        $entity = ElementTable::getEntity();
        $query = new Query($entity);
        $query
            ->setLimit(1)
            ->setFilter([
                'IBLOCK_ID' => $core->getIblockId($core::IBLOCK_CODE_WATERMARKS),
                'ACTIVE' => 'Y'
            ])
            ->setOrder([
                'SORT' => 'ASC'
            ])
            ->setSelect([
                'ID',
                'IMAGE' => 'PREVIEW_PICTURE',
                'WATERMARK' => 'DETAIL_PICTURE',
                'ALPHA_LEVEL' => 'ELEMENT_PROPERTY.VALUE',
            ])
            ->registerRuntimeField('PROPERTY', [
                'data_type' => PropertyTable::class,
                'reference' => Join::on('ref.IBLOCK_ID', 'this.IBLOCK_ID')
                    ->whereIn('ref.CODE', ['ALPHA_LEVEL']),
                'join_type' => 'inner',
            ])
            ->registerRuntimeField('ELEMENT_PROPERTY', [
                'data_type' => ElementPropertyTable::class,
                'reference' => Join::on('ref.IBLOCK_PROPERTY_ID', 'this.PROPERTY.ID')
                    ->whereColumn('ref.IBLOCK_ELEMENT_ID', 'this.ID'),
                'join_type' => 'inner',
            ])
        ;

        $item = $query->fetch();

        $alpha = $item['ALPHA_LEVEL'] > 100 ? 100 : ($item['ALPHA_LEVEL'] < 1 ? 1 : $item['ALPHA_LEVEL']);

        // Если нет готового водяного знака то создадим, иначе берем его
        if (empty($item['WATERMARK'])) {

            $watermark = $image = CFile::GetPath($item['IMAGE']) ?: '';

            if ($image) {

                // Установим прозрачность
                $watermark = static::createImageAlpha($image, $alpha);

                // Сохраним
                (new CIBlockElement())->Update($item['ID'], [
                    'DETAIL_PICTURE' => CFile::MakeFileArray($watermark)
                ]);
            }

        } else {
            $watermark = CFile::GetPath($item['WATERMARK']) ?: '';
        }

        // Определяем точный путь
        $watermark = $this->getFileRealPath($watermark);

        return [$watermark, $alpha];
    }

    /**
     * Возвращает логи ошибок
     * @return array
     */
    public function getErrorLogs(string $imagePath = ''): ?array
    {
        if (empty($imagePath)) return $this->errorLogs;
        return $this->errorLogs[$imagePath];
    }

    /**
     * Удаление созданных файлов, если $newFile = true
     */
    public function __destruct()
    {
        // Удаляем обработанные изображения
        foreach ($this->deleteFilePaths as $deleteFilePath) {
            if (!file_exists($deleteFilePath)) continue;
            unlink($deleteFilePath);
            rmdir(dirname($deleteFilePath));
        }

        if ($this->errorLogs) var_dump($this->errorLogs);
    }

    public static function getBaseDir(): string
    {
        return self::$baseDir;
    }
}