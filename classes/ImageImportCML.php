<?php

class ImageImportCML extends ImportCML
{
    public $idEntityCMLName = null;

    public function __construct()
    {
        // Удалить Image без изображений
        foreach (Image::getAllImages() as $image) {
            $idImage = $image['id_image'];
            $dir = _PS_PROD_IMG_DIR_.Image::getImgFolderStatic($idImage);
            $pathImg = $dir.$idImage.'.jpg';
            if (!file_exists($pathImg) || !filesize($pathImg)) {
                (new Image($idImage))->delete();
                // Image::deleteImage() не срабатывает если нет базового изображения, удалить папку самостоятельно
                $rmDir = true;
                // Удалить папку если она пуста и в ней только один файл index.php
                foreach (scandir($dir) as $file) {
                    if ($file[0] != '.' && $file != 'index.php') {
                        $rmDir = false;
                        break;
                    }
                }
                if ($rmDir) {
                    @unlink($dir.'index.php');
                    @rmdir($dir);
                }
            }
        }
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
        if (!file_exists($oldPath)) {
            $img->delete();
            throw new ImportCMLException('Файл изображения не был загружен');
        }
        if (!ImageManager::isCorrectImageFileExt($filename) || !ImageManager::isRealImage($oldPath)) {
            $img->delete();
            throw new ImportCMLException('Изображение товара имеет неверный формат или не является изображением');
        }
        $newPath = $img->getPathForCreation().'.'.$img->image_format;

        // AdminImagesController::_regenerateNewImages() работает только с jpg
        if ($ext != 'jpg') {
            // Происходит поворот изображений с метаданными об ориентации, которые были перевернуты в другом ПО
            if (!ImageManager::resize($oldPath, $newPath)) {
                $img->delete();
                throw new ImportCMLException('Не могу сохранить изображение товара');
            }
            @unlink($oldPath);
        } elseif (!@rename($oldPath, $newPath)) {
            throw new ImportCMLException('Не могу сохранить изображение товара');
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
            throw new ImportCMLException("Не могу сгенерировать изображение товара, $existingImg не существует");
        }
        foreach ($imageType as $type) {
            $newImg = $existingPath.'-'.stripcslashes($type['name']).'.'.$img->image_format;
            if (file_exists($newImg)) {
                continue;
            }
            if (!ImageManager::resize($existingImg, $newImg, (int) $type['width'], (int) $type['height'])) {
                throw new ImportCMLException("Ошибка генерации изображения товара $newImg");
            } elseif ($generateHightDpiImages) {
                $newImg = $existingPath.'-'.stripcslashes($type['name']).'2x.'.$img->image_format;
                if (!ImageManager::resize($existingImg, $newImg, (int) $type['width'] * 2, (int) $type['height'] * 2)) {
                    throw new ImportCMLException("Ошибка генерации HIGHT_DPI изображения товара $newImg");
                }
            }
        }

        // Hook::exec('actionWatermark', array('id_image' => $img->id, 'id_product' => $img->id_product));

        return true;
    }
}
