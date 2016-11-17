<?php

class ImageImportCML extends ImportCML
{
    public $idEntityCMLName = null;

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
        /** @var Image $target */
        $target = $this->targetClass;
        $filename = (string) $this->xml;
        $ext = pathinfo($filename, PATHINFO_EXTENSION);

        $oldPath = $uploadDir.$filename;
        if (!file_exists($oldPath)) {
            $target->delete();
            throw new ImportCMLException('Файл изображения не был загружен');
        }
        if (!ImageManager::isCorrectImageFileExt($filename) || !ImageManager::isRealImage($oldPath)) {
            $target->delete();
            throw new ImportCMLException('Изображение товара имеет неверный формат или не является изображением');
        }
        $newPath = $target->getPathForCreation().".$ext";

        if (!@rename($oldPath, $newPath)) {
            $target->delete();
            throw new ImportCMLException('Не могу сохранить изображение товара');
        }
        // todo ??? resize image
        return true;
    }
}
