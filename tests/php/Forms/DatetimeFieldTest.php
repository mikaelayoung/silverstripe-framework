<?php

namespace SilverStripe\Forms\Tests;

use SilverStripe\Dev\SapphireTest;
use SilverStripe\Control\Controller;
use SilverStripe\Forms\DatetimeField;
use SilverStripe\Forms\RequiredFields;
use SilverStripe\Forms\DateField;
use SilverStripe\Forms\SeparatedDateField;
use SilverStripe\Forms\Tests\DatetimeFieldTest\Model;
use SilverStripe\Forms\TimeField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\Form;
use SilverStripe\i18n\i18n;

class DatetimeFieldTest extends SapphireTest
{
    protected $timezone = null;

    public function setUp()
    {
        parent::setUp();
        i18n::set_locale('en_NZ');
        $this->timezone = date_default_timezone_get();
    }

    public function tearDown()
    {
        date_default_timezone_set($this->timezone);
        parent::tearDown(); // TODO: Change the autogenerated stub
    }

    public function testFormSaveInto()
    {
        $dateTimeField = new DatetimeField('MyDatetime');
        $form = $this->getMockForm();
        $form->Fields()->push($dateTimeField);

        // en_NZ standard format
        $dateTimeField->setSubmittedValue([
            'date' => '29/03/2003',
            'time' => '11:59:38 pm'
        ]);
        $validator = new RequiredFields();
        $this->assertTrue($dateTimeField->validate($validator));
        $m = new Model();
        $form->saveInto($m);
        $this->assertEquals('2003-03-29 23:59:38', $m->MyDatetime);
    }

    public function testDataValue()
    {
        $f = new DatetimeField('Datetime');
        $this->assertEquals(null, $f->dataValue(), 'Empty field');

        $f = new DatetimeField('Datetime', null, '2003-03-29 23:59:38');
        $this->assertEquals('2003-03-29 23:59:38', $f->dataValue(), 'From date/time string');
    }

    public function testConstructorWithoutArgs()
    {
        $f = new DatetimeField('Datetime');
        $this->assertEquals($f->dataValue(), null);
    }

    // /**
    //  * @expectedException InvalidArgumentException
    //  */
    // public function testConstructorWithLocalizedDateString() {
    // 	$f = new DatetimeField('Datetime', 'Datetime', '29/03/2003 23:59:38');
    // }

    public function testConstructorWithIsoDate()
    {
        // used by Form->loadDataFrom()
        $f = new DatetimeField('Datetime', 'Datetime', '2003-03-29 23:59:38');
        $this->assertEquals($f->dataValue(), '2003-03-29 23:59:38');
    }

    // /**
    //  * @expectedException InvalidArgumentException
    //  */
    // public function testSetValueWithDateString() {
    // 	$f = new DatetimeField('Datetime', 'Datetime');
    // 	$f->setValue('29/03/2003');
    // }

    public function testSetValueWithDateTimeString()
    {
        $f = new DatetimeField('Datetime', 'Datetime');
        $f->setValue('2003-03-29 23:59:38');
        $this->assertEquals($f->dataValue(), '2003-03-29 23:59:38');
    }

    public function testSetValueWithArray()
    {
        $datetimeField = new DatetimeField('Datetime', 'Datetime');
        // Values can only be localized (= non-ISO) in array notation
        $datetimeField->setSubmittedValue([
            'date' => '29/03/2003',
            'time' => '11:00:00 pm'
        ]);
        $this->assertEquals($datetimeField->dataValue(), '2003-03-29 23:00:00');
    }

    public function testSetValueWithDmyArray()
    {
        $f = new DatetimeField('Datetime', 'Datetime');
        $f->setDateField(new SeparatedDateField('Datetime[date]'));
        $f->setSubmittedValue([
            'date' => ['day' => 29, 'month' => 03, 'year' => 2003],
            'time' => '11:00:00 pm'
        ]);
        $this->assertEquals($f->dataValue(), '2003-03-29 23:00:00');
    }

    public function testValidate()
    {
        $f = new DatetimeField('Datetime', 'Datetime', '2003-03-29 23:59:38');
        $this->assertTrue($f->validate(new RequiredFields()));

        $f = new DatetimeField('Datetime', 'Datetime', '2003-03-29 00:00:00');
        $this->assertTrue($f->validate(new RequiredFields()));

        $f = new DatetimeField('Datetime', 'Datetime', 'wrong');
        $this->assertFalse($f->validate(new RequiredFields()));
    }

    public function testTimezoneSet()
    {
        date_default_timezone_set('Europe/Berlin');
        // Berlin and Auckland have 12h time difference in northern hemisphere winter
        $datetimeField = new DatetimeField('Datetime', 'Datetime');
        $datetimeField->setTimezone('Pacific/Auckland');
        $datetimeField->setValue('2003-12-24 23:59:59');
        $this->assertEquals(
            '25/12/2003 11:59:59 AM',
            $datetimeField->Value(),
            'User value is formatted, and in user timezone'
        );
        $this->assertEquals('25/12/2003', $datetimeField->getDateField()->Value());
        $this->assertEquals('11:59:59 AM', $datetimeField->getTimeField()->Value());
        $this->assertEquals(
            '2003-12-24 23:59:59',
            $datetimeField->dataValue(),
            'Data value is unformatted, and in server timezone'
        );
    }

    public function testTimezoneFromConfig()
    {
        date_default_timezone_set('Europe/Berlin');
        // Berlin and Auckland have 12h time difference in northern hemisphere summer, but Berlin and Moscow only 2h.
        $datetimeField = new DatetimeField('Datetime', 'Datetime');
        $datetimeField->setTimezone('Europe/Moscow');
        $datetimeField->setSubmittedValue([
            // pass in default format, at user time (Moscow)
            'date' => '24/06/2003',
            'time' => '11:59:59 pm',
        ]);
        $this->assertTrue($datetimeField->validate(new RequiredFields()));
        $this->assertEquals('2003-06-24 21:59:59', $datetimeField->dataValue(), 'Data value matches server timezone');
    }

    public function testSetDateField()
    {
        $form = $this->getMockForm();
        $field = new DatetimeField('Datetime', 'Datetime');
        $field->setForm($form);
        $field->setSubmittedValue([
            'date' => '24/06/2003',
            'time' => '23:59:59',
        ]);
        $dateField = new DateField('Datetime[date]');
        $field->setDateField($dateField);

        $this->assertEquals(
            $dateField->getForm(),
            $form,
            'Sets form on new field'
        );

        $this->assertEquals(
            '2003-06-24',
            $dateField->dataValue(),
            'Sets existing value on new field'
        );
    }

    public function testSetTimeField()
    {
        $form = $this->getMockForm();
        $field = new DatetimeField('Datetime', 'Datetime');
        $field->setForm($form);
        $field->setSubmittedValue([
            'date' => '24/06/2003',
            'time' => '11:59:59 pm',
        ]);
        $timeField = new TimeField('Datetime[time]');
        $field->setTimeField($timeField);

        $this->assertEquals(
            $timeField->getForm(),
            $form,
            'Sets form on new field'
        );

        $this->assertEquals(
            '23:59:59',
            $timeField->dataValue(),
            'Sets existing value on new field'
        );
    }

    public function testGetName()
    {
        $field = new DatetimeField('Datetime');

        $this->assertEquals('Datetime', $field->getName());
        $this->assertEquals('Datetime[date]', $field->getDateField()->getName());
        $this->assertEquals('Datetime[time]', $field->getTimeField()->getName());
    }

    public function testSetName()
    {
        $field = new DatetimeField('Datetime', 'Datetime');
        $field->setName('CustomDatetime');
        $this->assertEquals('CustomDatetime', $field->getName());
        $this->assertEquals('CustomDatetime[date]', $field->getDateField()->getName());
        $this->assertEquals('CustomDatetime[time]', $field->getTimeField()->getName());
    }

    protected function getMockForm()
    {
        /** @skipUpgrade */
        return new Form(
            new Controller(),
            'Form',
            new FieldList(),
            new FieldList(
                new FormAction('doSubmit')
            )
        );
    }
}
