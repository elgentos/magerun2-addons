<?php
/**
 * Byte Hypernode Magerun
 *
 * @package     hypernode-Magerun
 * @author      Byte
 * @copyright   Copyright (c) 2017 Byte
 * @license     http://opensource.org/licenses/osl-3.0.php Open Software License 3.0 (OSL-3.0)
 */

namespace Hypernode\PasswordCracker\Mutator;

class AsciiIncrement extends AbstractMutator
{
    public static function getIdentifier()
    {
        return '+';
    }

    public static function getLength()
    {
        return 2;
    }

    public static function validate($mutator)
    {
        return preg_match('~^' . preg_quote(self::getIdentifier(), '~'). '\d$~', $mutator);
    }

    public function mutate($input)
    {
        $i = $this->getPositionArg(1);
        if (! $this->validatePosition($i, $input)) {
            return $input;
        }

        $inputArray = preg_split('//u', $input, -1, PREG_SPLIT_NO_EMPTY); // Convert string to an array
        $c = $this->unichr($this->uniord($inputArray[$i]) - 1); // Manipulate the element

        return substr_replace($input, $c, $i, 1);
    }
}
