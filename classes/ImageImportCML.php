<?php

class ImageImportCML extends ImportCML
{
    public $idEntityCMLName = null;

    public function __construct()
    {
//        // Удалить Image без изображений
//        foreach (Image::getAllImages() as $image) {
//            $idImage = $image['id_image'];
//            $dir = _PS_PROD_IMG_DIR_.Image::getImgFolderStatic($idImage);
//            $pathImg = $dir.$idImage.'.jpg';
//            if (!file_exists($pathImg) || !filesize($pathImg)) {
//                (new Image($idImage))->delete();
//                // Image::deleteImage() не срабатывает если нет базового изображения, удалить папку самостоятельно
//                $rmDir = true;
//                // Удалить папку если она пуста и в ней только один файл index.php
//                foreach (scandir($dir) as $file) {
//                    if ($file[0] != '.' && $file != 'index.php') {
//                        $rmDir = false;
//                        break;
//                    }
//                }
//                if ($rmDir) {
//                    @unlink($dir.'index.php');
//                    @rmdir($dir);
//                }
//            }
//        }
        parent::__construct();
    }

    public function setHash()
    {
        // Если учитывать position, тогда каждый импорт будет создавать новое изображение, так как позиция изменяется
        // В случаи если упустить id_product, то изображение не будет созданно для товара который был восстановлен
        $this->hash = md5($this->fields['id_product'].(string) $this->xml);
    }

    public function save()
    {
        static $uploadDir;

        if (!parent::save()) {
            return false;
        }

        if (!$this->targetClass) {
            return true;
        }

        if (!$uploadDir) {
            $uploadDir = WebserviceRequestCML::getInstance()->uploadDir;
        }
        /** @var Image $img */
        $img = $this->targetClass;
        $filename = (string) $this->xml;
        $ext = pathinfo($filename, PATHINFO_EXTENSION);

        $oldPath = $uploadDir.$filename;
        $productName = $this->targetClass->name[Context::getContext()->language->id];
        if (!file_exists($oldPath)) {
            self::setWarning("Файл изображения товара '{$productName}' не загружен");
            $img->delete();
            return false;
        }
        if (!ImageManager::isCorrectImageFileExt($filename) || !ImageManager::isRealImage($oldPath)) {
            self::setWarning("Изображение товара '{$productName}' имеет неверный формат");
            $img->delete();
            return false;
        }
        $newPath = $img->getPathForCreation().'.'.$img->image_format;

        // AdminImagesController::_regenerateNewImages() работает только с jpg
        if ($ext != 'jpg') {
            // Происходит поворот изображений с метаданными об ориентации, которые были перевернуты в другом ПО
            if (!ImageManager::resize($oldPath, $newPath)) {
                self::setWarning("Не могу изображение товара '{$productName}' преобразовать к нужному формату");
                $img->delete();
                return false;
            }
            @unlink($oldPath);
        } elseif (!@rename($oldPath, $newPath)) {
            self::setWarning("Не могу сохранить изображение товара '{$productName}'");
            $img->delete();
            return false;
        }

        $generateNewImage = (bool) Configuration::get(WebserviceRequestCML::MODULE_NAME.'-generateNewImage');
        if (!$generateNewImage) {
            return true;
        }

        $generateHightDpiImages = (bool) Configuration::get('PS_HIGHT_DPI');
        $imageType = ImageType::getImagesTypes('products');
        $existingPath = _PS_PROD_IMG_DIR_.$img->getImgPath();
        $existingImg = "$existingPath.jpg";

        if (!file_exists($existingImg) && !filesize($existingImg)) {
            self::setWarning("Не могу сгенерировать изображение товара, файл '$existingImg' не существует");
        } else {
            foreach ($imageType as $type) {
                $newImg = $existingPath.'-'.stripcslashes($type['name']).'.'.$img->image_format;
                if (file_exists($newImg)) {
                    continue;
                }
                if (!ImageManager::resize($existingImg, $newImg, (int) $type['width'], (int) $type['height'])) {
                    self::setWarning("Ошибка генерации изображения '{$img->id}' для товара '{$productName}'");
                } elseif ($generateHightDpiImages) {
                    $newImg = $existingPath.'-'.stripcslashes($type['name']).'2x.'.$img->image_format;
                    if (!ImageManager::resize(
                        $existingImg,
                        $newImg,
                        (int) $type['width'] * 2,
                        (int) $type['height'] * 2
                    )) {
                        self::setWarning("Ошибка генерации HIGHT_DPI изображения '{$img->id}' товара '{$productName}'");
                    }
                }
            }
        }

        // Hook::exec('actionWatermark', array('id_image' => $img->id, 'id_product' => $img->id_product));

        return true;
    }

    public static function setCover($idImg, $idProduct)
    {
        $idImgCover = Image::getGlobalCover($idProduct)['id_image'];
        if ($idImg == $idImgCover) {
            return;
        }
        $db = Db::getInstance();
        if (!(Image::deleteCover($idProduct) && $db->update(
                Image::$definition['table'],
                array('cover' => 1),
                Image::$definition['primary']." = $idImg"
            ) && $db->update(
                Image::$definition['table'].'_shop',
                array('cover' => 1),
                Image::$definition['primary']." = $idImg"
            ))) {
            $productName = Product::getProductName($idProduct);
            self::setWarning("Не могу установить обложку товара '{$productName}'");
        }
    }
}
