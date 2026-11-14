<?php

namespace Tests\Unit;

use App\Support\PersonName;
use PHPUnit\Framework\TestCase;

class PersonNameTest extends TestCase
{
    public function test_formats_the_title_and_credentials_around_the_structured_name(): void
    {
        $this->assertSame('Juan Dela Cruz', PersonName::format(null, 'Juan', null, 'Dela Cruz'));
        $this->assertSame('Dr. Juan Dela Cruz', PersonName::format('Dr.', 'Juan', null, 'Dela Cruz'));
        $this->assertSame('Juan Dela Cruz, DM', PersonName::format(null, 'Juan', '', 'Dela Cruz', null, 'DM'));
        $this->assertSame('Dr. Juan P. Dela Cruz Jr., DM', PersonName::format('Dr.', 'Juan', 'P', 'Dela Cruz', 'Jr.', 'DM'));
    }

    public function test_shows_a_full_middle_name_as_its_initial(): void
    {
        $this->assertSame('Juan P. Dela Cruz', PersonName::format(null, 'Juan', 'pascual', 'Dela Cruz'));
        $this->assertSame('Dr. Juan Pascual Dela Cruz, DM', PersonName::format('Dr.', 'Juan', 'Pascual', 'Dela Cruz', null, 'DM', fullMiddleName: true));
    }

    public function test_never_leaves_extra_spaces_or_dangling_commas(): void
    {
        $this->assertSame('Juan Dela Cruz', PersonName::format('  ', ' Juan ', ' ', ' Dela  Cruz ', '', '  '));
        $this->assertSame('Juan Dela Cruz, PhD, EdD', PersonName::format(null, 'Juan', null, 'Dela Cruz', null, ' , PhD,  EdD , '));
    }

    public function test_cleaning_trims_but_keeps_capitalization_and_punctuation(): void
    {
        $this->assertSame('PhD, EdD', PersonName::cleanCredentials("  PhD,   EdD\t"));
        $this->assertSame('M.A.Ed.', PersonName::cleanCredentials('M.A.Ed.'));
        $this->assertNull(PersonName::cleanCredentials(' , '));
        $this->assertNull(PersonName::clean('   '));
        $this->assertSame('Dr.', PersonName::clean(' Dr. '));
    }
}
