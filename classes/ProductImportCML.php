<?php

class ProductImportCML extends ImportCML
{
    public $idEntityCMLName = array('Ид', 'Штрихкод', 'Артикул');
    public $cache = false;

    public $map = array(
        'reference' => 'Артикул',

        'name' => 'Наименование',
        'description' => 'Описание',
    );
    public $categories = array();
    public static $prodToUpdSpecPriceRule = array();

    public function __construct()
    {
        $param = true;
        $this->map['reference'] = $param ? 'Артикул' : 'Штрихкод';
        parent::__construct();
    }

    public static function postImport()
    {
        // Обновить правила каталога для товаров у которых сменилась категори(и|я)
        SpecificPriceRule::applyAllRules(self::$prodToUpdSpecPriceRule);
    }

    public function save()
    {
        // Удалить обьект если он был удален в ERP
        if (self::getXmlElemAttrValue($this->xml, 'СтатусТип') == 'Удален') {
            if ($this->entity->id_target) {
                (new Product($this->entity->id_target))->delete();
                $this->entity->delete();
            }
            return true;
        }

        if (!parent::save()) {
            return false;
        }

        $cats = $this->categories;
        $oldCats = Product::getProductCategories($this->entity->id_target);
        $newCats = array_diff($cats, $oldCats);
        $delCats = array_diff($oldCats, $cats);

        if ($delCats || $newCats) {
            /** @var Product $product */
            $product = $this->targetClass;
            // Товар мог существовать и не требовал его создания или обновления
            !$product && $product = new Product($this->entity->id_target);

            // deleteCategory|updateCategories делают вызов SpecificPriceRule::applyAllRules(),
            // что критично для импорта большого числа товаров, стоит сделать вызов applyAllRules для множества
            self::$prodToUpdSpecPriceRule[] = $product->id;
            if ((empty($delCats) || !$product->deleteCategories(true)) && !$product->addToCategories($cats)) {
                throw new ImportCMLException('Не могу добавить категории (Группы) к товару');
            }
        }

        if (isset($this->xml->Картинка)) {
            $idProduct = $this->entity->id_target;
            $fields = array('id_product' => $idProduct);
            $position = Image::getHighestPosition($idProduct);
            $cover = !Image::hasImages($this->idLangDefault, $idProduct);

            foreach ($this->xml->Картинка as $img) {
                $fields['position'] = ++$position;
                $fields['cover'] = $cover;
                $cover && $cover = false;
                ImportCML::catchBall($img->getName(), $img, $fields);
            }
        }

        return true;
    }

    public function getCalcFields()
    {
        $fields = array();


        if (isset($this->xml->ТорговаяМарка)) {
            $fields['id_manufacturer']  = self::catchBall(
                $this->xml->ТорговаяМарка->getName(),
                null,
                array('name' => (string) $this->xml->ТорговаяМарка)
            );
        }

        if (isset($this->xml->Штрихкод)) {
            $upcOrEan13 = (string) $this->xml->Штрихкод;
            $fields[Tools::strlen($upcOrEan13) == 13 ? 'ean13' : 'upc'] = $upcOrEan13;
        }

        $this->categories = array();
        if (isset($this->xml->Группы)) {
            $categories = array();
            foreach ($this->xml->Группы->children() as $idCategory) {
                $idCategory = EntityCML::getIdTarget((string) $idCategory, null, true);
                if (!$idCategory) {
                    throw new ImportCMLException('Категория (Группа) для товара не существует');
                }
                $categories[] = $idCategory;
            }
            if (!empty($categories)) {
                $this->categories = $categories;
                $fields['id_category_default'] = $categories[0];
            }
        }

        return array_merge($fields, parent::getCalcFields());
    }
}
