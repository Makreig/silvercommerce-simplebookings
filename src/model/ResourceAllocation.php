<?php

namespace ilateral\SimpleBookings\Model;

use SilverStripe\ORM\DataObject;
use ilateral\SimpleBookings\Products\BookableProduct;

/**
 * Pre-allocate resources (for example over christmas) that will be accounted for
 * when attempting to calculate bookings
 */
class ResourceAllocation extends DataObject
{
    private static $table_name = "SimpleBookings_ResourceAllocation";

    private static $db = array(
        'Title' => 'Varchar',
        'Start' => 'DBDatetime',
        'End'   => 'DBDatetime',
        'AllocateAll' => 'Boolean'   
    );

    private static $many_many = array(
        'Resources' => BookableProduct::class
    );

    private static $many_many_extraFields = array(
        'Resources' => [
            'Quantity' => 'Int',
            'AllocateAll' => 'Boolean',
            'Increase' => 'Boolean'
        ]
    );

    private static $summary_fields = [
        'FullTitle' => 'Title',
        'ResourcesList' => 'Resources',
        'AllocateAll' => 'Allocated All',
        'Start' => 'Start',
        'End' => 'End'
    ];

    private static $casting = [
        'FullTitle' => 'Varchar(255)',
        'ResourcesList' => 'Varchar(255)'
    ];

    /**
     * Return a more comprehensive title for this object
     * 
     * @return string
     */
    public function getFullTitle() 
    {
        if ($this->Title) {
            return $this->Title;
        }
        return $this->Start . ' - ' . $this->End;
    }

    /**
     * Return a rendered list of resources contained in this booking
     * 
     * @return string
     */
    public function getResourcesList() 
    {
        $list = [];

        foreach ($this->Resources() as $resource) {
            $list[] = $resource->Title;
        }

        return implode(', ', $list);
    }

    /**
     * {@inheritdoc}
     * 
     * @return FieldList
     */
    public function getCMSFields()
    {
        $fields = parent::getCMSFields();

        // Setup calendars on date fields
        $start_field = $fields->dataFieldByName("Start");
        $end_field = $fields->dataFieldByName("End");

        if ($start_field && $end_field) {
            $start_field
                ->getDateField()
                ->setConfig("showcalendar", true);

            $end_field
                ->getDateField()
                ->setConfig("showcalendar", true);
        }

        // Add editable fields to manage quantity
        $resources_field = $fields->dataFieldByName("Resources");

        if ($resources_field) {
            $config = $resources_field->getConfig();

            $editable_cols = new GridFieldEditableColumns();
            $editable_cols
                ->setDisplayFields(
                    array(
                    'Title' => array(
                        'field' => 'ReadonlyField',
                        'title' => _t("SimpleBookings.Title", "Title")
                    ),
                    'AvailablePlaces' => array(
                        'field' => 'ReadonlyField',
                        'title' => _t("SimpleBookings.TotalPlaces", "total Places")
                    ),
                    'Quantity' => array(
                        'field' => 'NumericField',
                        'title' => _t("SimpleBookings.NumbertoAllocate", "Number to Allocate")
                    ),
                    'AllocateAll' => array(
                        'field' => 'CheckBoxField',
                        'title' => _t("SimpleBookings.AllocateAll", "Allocate All")
                    ),
                    'Increase' => array(
                        'field' => 'CheckBoxField',
                        'title' => _t("SimpleBookings.Increase", "Increase availability")
                    )
                    )
                );


            $config
                ->removeComponentsByType("GridFieldAddNewButton")
                ->removeComponentsByType("GridFieldDeleteAction")
                ->removeComponentsByType("GridFieldEditButton")
                ->removeComponentsByType("GridFieldDetailForm")
                ->removeComponentsByType("GridFieldDataColumns")
                ->addComponent($editable_cols)
                ->addComponent(new GridFieldDeleteAction(true));
            
            $fields->removeByName("Resources");
            
            $fields->addFieldToTab(
                "Root.Main",
                $resources_field
            );
        }

        return $fields;
    }
}
