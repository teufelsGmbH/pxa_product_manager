<?php

namespace Pixelant\PxaProductManager\Tests\Unit\Adapter\Attributes;

use Nimut\TestingFramework\TestCase\UnitTestCase;
use Pixelant\PxaProductManager\Domain\Adapter\Attributes\GeneralAdapter;
use Pixelant\PxaProductManager\Domain\Model\Attribute;
use Pixelant\PxaProductManager\Domain\Model\AttributeValue;
use Pixelant\PxaProductManager\Domain\Model\Product;


/**
 * @package Pixelant\PxaProductManager\Tests\Unit\Adapter\Attributes
 */
class GeneralAdapterTest extends UnitTestCase
{
    protected $subject;

    protected function setUp()
    {
        parent::setUp();

        $this->subject = new GeneralAdapter();
    }

    /**
     * @test
     */
    public function searchAttributeValueReturnNullIfValueNotFound()
    {
        $attribute = createEntity(Attribute::class, 1);
        $product = createEntity(Product::class, 1);

        $this->assertNull($this->callInaccessibleMethod($this->subject, 'searchAttributeValue', $product, $attribute));
    }

    /**
     * @test
     */
    public function searchAttributeValueReturnCorrespondingForAttributeValue()
    {
        $attribute1 = createEntity(Attribute::class, 10);
        $attribute2 = createEntity(Attribute::class, 50);
        $attribute3 = createEntity(Attribute::class, 100);

        $attributeValue1 = createEntity(AttributeValue::class, ['uid' => 1, 'attribute' => $attribute1]);
        $attributeValue2 = createEntity(AttributeValue::class, ['uid' => 2, 'attribute' => $attribute2]);
        $attributeValue3 = createEntity(AttributeValue::class, ['uid' => 2, 'attribute' => $attribute3]);

        /** @var Product $product */
        $product = createEntity(Product::class, 1);
        $product->setAttributeValues(createObjectStorage($attributeValue1, $attributeValue2, $attributeValue3));

        $this->assertSame(
            $attributeValue3,
            $this->callInaccessibleMethod($this->subject, 'searchAttributeValue', $product, $attribute3)
        );
    }

    /**
     * @test
     */
    public function adaptWillSetValueOfAttributeValueForAttribute()
    {
        $attributeValue = $this->prophesize(AttributeValue::class);
        $attributeValue->getValue()->shouldBeCalled()->willReturn('testvalue');

        $attribute = createEntity(Attribute::class, 1);
        $product = createEntity(Product::class, 1);

        $adapter = $this->createPartialMock(GeneralAdapter::class, ['searchAttributeValue']);
        $adapter->expects($this->once())->method('searchAttributeValue')->willReturn($attributeValue->reveal());

        $adapter->adapt($product, $attribute);

        $this->assertEquals('testvalue', $attribute->getValue());
    }
}
