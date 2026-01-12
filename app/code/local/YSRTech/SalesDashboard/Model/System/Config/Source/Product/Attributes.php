<?php
/**
 * YSRTech SalesDashboard Product Attributes Source Model
 *
 * @category   YSRTech
 * @package    YSRTech_SalesDashboard
 */
class YSRTech_SalesDashboard_Model_System_Config_Source_Product_Attributes
{
    /**
     * Get all product attributes as options
     */
    public function toOptionArray()
    {
        $options = array(
            array('value' => '', 'label' => '-- Please Select --'),
        );
        
        $attributes = Mage::getResourceModel('catalog/product_attribute_collection')
            ->addFieldToFilter('frontend_input', array('in' => array('select', 'multiselect')))
            ->setOrder('frontend_label', 'ASC');
        
        foreach ($attributes as $attribute) {
            $options[] = array(
                'value' => $attribute->getAttributeCode(),
                'label' => $attribute->getFrontendLabel() ? $attribute->getFrontendLabel() : $attribute->getAttributeCode()
            );
        }
        
        return $options;
    }
}
