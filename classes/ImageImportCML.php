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
        /** @var Image $targetClass */
        $targetClass = $this->targetClass;
        $newPath = $img->getPathForCreation().'.'.$img->image_format;
        $warning = '';

        if (!file_exists($oldPath)) {
            $warning = "Файл изображения товар(а|ов) '%s' не загружен(ы)";
        } elseif (!ImageManager::isCorrectImageFileExt($filename) || !ImageManager::isRealImage($oldPath)) {
            $warning = "Изображение товар(а|ов) '%s' име(ет|ют) неверный формат";
        // AdminImagesController::_regenerateNewImages() работает только с jpg
        } elseif ($ext != 'jpg') {
            // Происходит поворот изображений с метаданными об ориентации, которые были перевернуты в другом ПО
            if (!ImageManager::resize($oldPath, $newPath)) {
                $warning = "Не могу изображение товар(а|ов) '%s' преобразовать к нужному формату";
            }
            @unlink($oldPath);
        } elseif (!@copy($oldPath, $newPath)) {
            $warning = "Не могу сохранить изображение товар(а|ов) '%s'";
        }

        if ($warning) {
            $productName = Product::getProductName($targetClass->id_product);
            self::setWarning($warning, $productName);
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
            self::setWarning(
                "Не могу сгенерировать изображение товар(а|ов), файл(ы) '%s' не существу(ет|ют)",
                $existingImg
            );
        } else {
            foreach ($imageType as $type) {
                $newImg = $existingPath.'-'.stripcslashes($type['name']).'.'.$img->image_format;
                if (file_exists($newImg)) {
                    continue;
                }
                if (!ImageManager::resize($existingImg, $newImg, (int) $type['width'], (int) $type['height'])) {
                    $warning = "Ошибка генерации изображени(я|ий) для товар(а|ов) '%s'";
                } elseif ($generateHightDpiImages) {
                    $newImg = $existingPath.'-'.stripcslashes($type['name']).'2x.'.$img->image_format;
                    if (!ImageManager::resize(
                        $existingImg,
                        $newImg,
                        (int) $type['width'] * 2,
                        (int) $type['height'] * 2
                    )) {
                        $warning = "Ошибка генерации HIGHT_DPI изображени(я|ий) для товар(а|ов) '%s'";
                    }
                }
                if ($warning) {
                    !isset($productName) && $productName = Product::getProductName($targetClass->id_product);
                    self::setWarning($warning, $productName);
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
            self::setWarning("Не могу установить обложку для товар(а|ов) '%s'", Product::getProductName($idProduct));
        }
    }
}
