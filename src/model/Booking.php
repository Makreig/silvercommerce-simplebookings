<?php

namespace ilateral\SimpleBookings\Model;

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Control\Controller;
use SilverStripe\Security\Permission;
use SilverCommerce\OrdersAdmin\Model\Invoice;
use SilverStripe\Security\PermissionProvider;
use ilateral\SimpleBookings\Admin\BookingAdmin;
use ilateral\SimpleBookings\Helpers\BookingHelper;
use SilverCommerce\CatalogueAdmin\Model\CatalogueProduct;
use SilverCommerce\ContactAdmin\Model\Contact;
use SilverCommerce\OrdersAdmin\Admin\OrderAdmin;
use SilverCommerce\OrdersAdmin\Factory\LineItemFactory;
use SilverCommerce\OrdersAdmin\Model\LineItem;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Security\Security;

/**
 * A single booking that is linked to an invoice. Each lineitem on the Invoice constitutes a resource on this booking
 *
 * @method LineItem Item
 */
class Booking extends DataObject implements PermissionProvider
{
    private static $table_name = "SimpleBookings_Booking";

    private static $statuses = [
        'pending'   => 'Pending',
        'confirmed' => 'Confirmed',
        'cancelled' => 'Cancelled'
    ];

    private static $pending_status = 'pending';

    private static $confirmed_status = 'confirmed';

    private static $cancelled_status = 'cancelled';

    private static $db = [
        'StockID'       => 'Varchar',
        'Status'        => 'Varchar',
        'Start'         => 'Datetime',
        'End'           => 'Datetime',
        'Spaces'        => 'Int'
    ];

    private static $has_one = [
        'Customer' => Contact::class,
        'Item' => LineItem::class
    ];

    private static $casting = [
        'SpacesRemaining'=> 'Int',
        'Overbooked'     => 'Boolean',
        'TotalCost'      => 'Currency'
    ];

    private static $field_labels = [
        'StockID'           => 'Product',
        'Spaces'            => 'Spaces to Book',
        'Start'             => 'Start Date & Time',
        'End'               => 'End Date & Time',
        'Customer.FirstName' => 'First Name',
        'Customer.Surname'   => 'Surname',
        'Customer.Email'     => 'Email',
        'Invoice.FullRef'   => 'Invoice Ref'
    ];

    private static $summary_fields = [
        'ID',
        'Start',
        'End',
        'Title',
        'Spaces',
        'Customer.FirstName',
        'Customer.Surname',
        'Customer.Email',
        'Invoice.FullRef',
        'Status'
    ];

    private static $defaults = [
        "Status"      => 'pending'
    ];

    private static $cascade_deletes = [
        'Item'
    ];

    /**
     * Use the base title for the original bookable product
     *
     * @return string
     */
    public function getTitle()
    {
        return $this->getBaseProduct()->Title;
    }

    /**
     * Mark this booking pending
     *
     * @return self
     */
    public function markPending()
    {
        $status = $this->config()->pending_status;
        $this->Status = $status;
        return $this;
    }

    /**
     * Is this booking pending?
     *
     * @return self
     */
    public function isPending()
    {
        return $this->Status == $this->config()->pending_status;
    }

    /**
     * Mark this booking as confirmed
     *
     * @return self
     */
    public function markConfirmed()
    {
        $status = $this->config()->confirmed_status;
        $this->Status = $status;
        return $this;
    }

    /**
     * Is this booking confirmed?
     *
     * @return self
     */
    public function isConfirmed()
    {
        return $this->Status == $this->config()->confirmed_status;
    }

    /**
     * Mark this booking as cancelled
     *
     * @return self
     */
    public function markCancelled()
    {
        $status = $this->config()->cancelled_status;
        $this->Status = $status;
        return $this;
    }

    /**
     * Is this booking confirmed?
     *
     * @return self
     */
    public function isCancelled()
    {
        return $this->Status == $this->config()->cancelled_status;
    }

    /**
     * Get the base invoice from the underlying line item
     *
     * @return Invoice
     */
    public function getInvoice()
    {
        return $this->Item()->Parent();
    }

    /**
     * Get the product this booking was made against
     *
     * @return CatalogueProduct
     */
    public function getBaseProduct()
    {
        $product = BookingHelper::getBookableProducts()
            ->filter('StockID', $this->StockID)
            ->first();

        if (empty($product)) {
            $product = CatalogueProduct::create();
            $product->ID = -1;
        }

        return $product;
    }

    /**
     * Get the number of spaces available for this booking
     *
     * @return int
     */
    public function getSpacesRemaining()
    {
        $product = $this->getBaseProduct();

        if ($product->exists() && !empty($this->Start) && !empty($this->End)) {
            $helper = BookingHelper::create($this->Start, $this->End, $product);
            return $helper->getRemainingSpaces();
        }

        return 0;
    }

    /**
     * Link to view this item in the CMS
     *
     * @return string
     */
    public function CMSViewLink()
    {
        return Controller::join_links(
            "admin",
            BookingAdmin::config()->url_segment,
            "Booking",
            "EditForm",
            "field",
            "Booking",
            "item",
            $this->ID,
            "view"
        );
    }

    /**
     * Link to view this item's order in the CMS
     *
     * @return string
     */
    public function CMSOrderLink()
    {
        $invoice = $this->getInvoice();
        if ($invoice->exists()) {
            return Controller::join_links(
                "admin",
                OrderAdmin::config()->url_segment,
                $invoice->ClassName,
                "EditForm",
                "field",
                $invoice->ClassName,
                "item",
                $invoice->ID,
                "view"
            );
        }

        return "";
    }

    /**
     * Determine if any of these products are overbooked
     *
     * @return boolean
     */
    public function getOverBooked()
    {
        $helper = BookingHelper::create($this->Start, $this->End, $this->getBaseProduct());
        $overbooked = false;
        $booked_spaces = $helper->getTotalBookedSpaces();

        // First get the number of places available for this booking

        // Then get the currently booked resources for this booking

        // Finaly tot up the numbers
        /*foreach ($this->Resources() as $product) {
            if ($product->getPlacesRemaining($product->Start, $product->End) < 0) {
                $overbooked = true;
            }
        }*/

        return $overbooked;
    }

    /**
     * Get the total cost of this booking, based on all products added
     * and the total number of days
     *
     * @return float
     */
    public function getTotalCost()
    {
        return $this->getInvoice()->getTotal();
    }

    /**
     * Link to edit this item in the CMS
     *
     * @return string
     */
    public function CMSEditLink()
    {
        return Controller::join_links(
            "admin",
            BookingAdmin::config()->url_segment,
            "Booking",
            "EditForm",
            "field",
            "Booking",
            "item",
            $this->ID,
            "edit"
        );
    }

    /**
     * {@inheritdoc}
     *
     * @return FieldList
     */
    public function getCMSFields()
    {
        $self = $this;
        $this->beforeUpdateCMSFields(
            function ($fields) use ($self) {
                $products = BookingHelper::getBookableProducts();

                // Swap out status and stock ID for dropdowns
                $fields->addFieldsToTab(
                    'Root.Main',
                    [
                        DropdownField::create('StockID', $this->fieldLabel('StockID'))
                            ->setSource($products->map('StockID', 'Title')),
                        DropdownField::create('Status', $this->fieldLabel('Status'))
                            ->setSource($self->config()->statuses),
                    ],
                    'Start'
                );

                // Add calculated booking info
                $fields->addFieldToTab(
                    'Root.Main',
                    ReadonlyField::create('SpacesRemaining', $this->fieldLabel('SpacesRemaining'))
                        ->setValue($this->SpacesRemaining),
                    'Spaces'
                );
            }
        );
        
        return parent::getCMSFields();
    }

    /**
     * {@inheritdoc}
     *
     * @return array
     */
    public function providePermissions()
    {
        return [
            "BOOKING_VIEW_BOOKINGS" => [
                'name' => 'View any booking',
                'help' => 'Allow user to view any booking',
                'category' => 'Bookings',
                'sort' => 99
            ],
            "BOOKING_CREATE_BOOKINGS" => [
                'name' => 'Create a booking',
                'help' => 'Allow user to create a booking',
                'category' => 'Bookings',
                'sort' => 98
            ],
            "BOOKING_EDIT_BOOKINGS" => [
                'name' => 'Edit any booking',
                'help' => 'Allow user to edit any booking',
                'category' => 'Bookings',
                'sort' => 97
            ],
            "BOOKING_DELETE_BOOKINGS" => [
                'name' => 'Delete any booking',
                'help' => 'Allow user to delete any booking',
                'category' => 'Bookings',
                'sort' => 96
            ]
        ];
    }

    /**
     * Return a member object, based on eith the passed param or
     * getting the currently logged in Member.
     *
     * @param Member $member Either a Member object or an Int
     *
     * @return Member | Null
     */
    protected function getMember($member = null)
    {
        if ($member && $member instanceof Member) {
            return $member;
        } elseif (is_numeric($member)) {
            return Member::get()->byID($member);
        } else {
            return Security::getCurrentUser();
        }
    }

    /**
     * Only users with VIEW admin rights can view
     *
     * @return Boolean
     */
    public function canView($member = null)
    {
        $extended = $this->extend('canView', $member);
        if ($extended && $extended !== null) {
            return $extended;
        }

        $member = $this->getMember($member);

        if ($member && Permission::checkMember($member->ID, ["ADMIN", "BOOKING_VIEW_BOOKINGS"])) {
            return true;
        }

        return false;
    }

    /**
     * Only users with create admin rights can create
     *
     * @return Boolean
     */
    public function canCreate($member = null, $context = [])
    {
        $extended = $this->extend('canCreate', $member);
        if ($extended && $extended !== null) {
            return $extended;
        }

        $member = $this->getMember($member);

        if ($member && Permission::checkMember($member->ID, ["ADMIN", "BOOKING_CREATE_BOOKINGS"])) {
            return true;
        }

        return false;
    }

    /**
     * Only users with EDIT admin rights can view an order
     *
     * @return Boolean
     */
    public function canEdit($member = null)
    {
        $extended = $this->extend('canEdit', $member);
        if ($extended && $extended !== null) {
            return $extended;
        }

        $member = $this->getMember($member);

        if ($member && Permission::checkMember($member->ID, ["ADMIN", "BOOKING_EDIT_BOOKINGS"])) {
            return true;
        }

        return false;
    }

    /**
     * Only users with Delete Permissions can delete Bookings
     *
     * @return Boolean
     */
    public function canDelete($member = null)
    {
        $extended = $this->extend('canEdit', $member);
        if ($extended && $extended !== null) {
            return $extended;
        }

        $member = $this->getMember($member);

        if ($member && Permission::checkMember($member->ID, ["ADMIN", "BOOKING_DELETE_BOOKINGS"])) {
            return true;
        }

        return false;
    }

    /**
     * Each line item that is bookable needs a relevent booking
     *
     */
    public function onAfterWrite()
    {
        parent::onAfterWrite();

        $line_item = $this->Item();

        // Create and attach a line item
        if (!$line_item->exists()) {
            $factory = LineItemFactory::create()
                ->setProduct($this->getBaseProduct())
                ->makeItem()
                ->write();
            $line_item = $factory->getItem();
            $this->ItemID = $line_item->ID;
            $this->write();
        }

        // ensure details are copied to line item (if needed)
        if ($line_item->exists() && ($line_item->StockID != $this->StockID || $line_item->Quantity != $this->Spaces)) {
            $line_item->StockID = $this->StockID;
            $line_item->Quantity = $this->Spaces;
            $line_item->write();
        }
    }
}
