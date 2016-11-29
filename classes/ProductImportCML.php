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
        SpecificPriceRule::applyAllRules(array_unique(self::$prodToUpdSpecPriceRule));
    }

    public function save()
    {
        static $syncWithoutImg = false;

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

        /** @var Product $product */
        $product = $this->targetClass;
        $idProduct = $this->entity->id_target;
        $db = Db::getInstance();


        if ($cats = $this->categories) {
            $oldCats = Product::getProductCategories($idProduct);
            $newCats = array_diff($cats, $oldCats);
            $delCats = array_diff($oldCats, $cats);

            if ($delCats || $newCats) {
                // Товар мог существовать и не требовал его создания или обновления
                !$product && $product = new Product($idProduct);

                // deleteCategory|updateCategories делают вызов SpecificPriceRule::applyAllRules(),
                // что критично для импорта большого числа товаров, стоит сделать вызов applyAllRules для множества
                self::$prodToUpdSpecPriceRule[] = $product->id;
                if ((empty($delCats) || !$product->deleteCategories(true)) && !$product->addToCategories($cats)) {
                    throw new ImportCMLException('Не могу добавить категории (Группы) к товару');
                }
            }
        }

        if (!$syncWithoutImg && isset($this->xml->Картинка)) {
            $fields = array('id_product' => $idProduct);
            $position = Image::getHighestPosition($idProduct);
            $cover = !Image::hasImages($this->idLangDefault, $idProduct);

            foreach ($this->xml->Картинка as $img) {
                // Если выбрана опция не загружать картинки во время синхронизации, тогда игнорировать изображения
                $path = WebserviceRequestCML::getInstance()->uploadDir.(string) $img;
                if (!file_exists($path)) {
                    $syncWithoutImg = true;
                    break;
                }
                $fields['position'] = ++$position;
                $fields['cover'] = $cover;
                $cover && $cover = false;
                ImportCML::catchBall($img->getName(), $img, $fields);
            }
        }

        if (Feature::isFeatureActive()) {
            if (isset($this->xml->ЗначенияСвойств)) {
                $featureValue = array();
                // $feature (ЗначенияСвойства)
                foreach ($this->xml->ЗначенияСвойств->children() as $feature) {
                    if ($value = (string) $feature->Значение) {
                        $featureValue[] = FeatureValueImportCML::getIdFeatureValue((string) $feature->Ид, $value);
                    }
                }
                $featureValue = array_unique($featureValue);

                $oldFeatureValue = array();
                foreach (Product::getFeaturesStatic($idProduct) as $row) {
                    $oldFeatureValue[] = $row['id_feature_value'];
                }
                $newFeatureValue = array_diff($featureValue, $oldFeatureValue);
                $delFeatureValue = array_diff($oldFeatureValue, $featureValue);
                if ($newFeatureValue || $delFeatureValue) {
                    $db->delete('feature_product', "id_product = $idProduct");
                    $db->execute(
                        "INSERT INTO "._DB_PREFIX_."feature_product (id_feature, id_product, id_feature_value)
                        SELECT id_feature, $idProduct, id_feature_value
                        FROM "._DB_PREFIX_."feature_value
                        WHERE id_feature_value IN (".implode(', ', $featureValue).")"
                    );

                    self::$prodToUpdSpecPriceRule[] = $idProduct;
                }
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
            foreach ($this->xml->Группы->children() as $guid) {
                $idCategory = EntityCML::getIdTarget((string) $guid, null, true);
                if (!$idCategory) {
                    throw new ImportCMLException('Категория (Группа) товара не существует');
                }
                $categories[] = $idCategory;
            }
            if (!empty($categories)) {
                $this->categories = array_unique($categories);
                $fields['id_category_default'] = $categories[0];
            }
        }
        $replaceEndBreak = (bool) Configuration::get(WebserviceRequestCML::MODULE_NAME.'-replaceEndBreak');
        $replaceEndBreak = true;
        if ($replaceEndBreak) {
            $fields['description'] = preg_replace('/\n/', '<br/>', $this->fields['description']);
        }
        return array_merge($fields, parent::getCalcFields());
    }

    public function getDefaultFields()
    {
        $fields = array(
            'id_category_default' => (int) Configuration::get('PS_HOME_CATEGORY'),
        );
        return array(parent::getDefaultFields(), $fields);
    }
}
