<?php

namespace allejo\DaPulse\Objects;

/**
 * Class PulseColumnTextValue
 *
 * @package allejo\DaPulse\Objects
 * @since   0.2.0
 */
class PulseColumnNumericValue extends PulseColumnValue
{
    /**
     * Get a numeric column's content
     *
     * @api
     *
     * @since  0.3.0 Docs correctly specify a numeric return type
     * @since  0.2.0
     *
     * @return int|double|null Null is returned if there is no value set for this column
     */
    public function getValue ()
    {
        return parent::getValue();
    }

    /**
     * Update the value of a numeric column
     *
     * @api
     *
     * @param int|double $number
     *
     * @since 0.3.0 \InvalidArgumentException is now thrown
     * @since 0.2.0
     *
     * @throws \InvalidArgumentException if $number is not a numeric value
     */
    public function updateValue ($number)
    {
        if (!is_numeric($number))
        {
            throw new \InvalidArgumentException('$number is expected to be a numeric type');
        }

        $url        = sprintf("%s/%d/columns/%s/numeric.json", self::apiEndpoint(), $this->board_id, $this->column_id);
        $postParams = array(
            "pulse_id" => $this->pulse_id,
            "value"    => $number
        );

        $result = self::sendPut($url, $postParams);
        $this->setValue($result);
    }

    protected function setValue ($response)
    {
        $this->column_value = $response["value"];
    }
}