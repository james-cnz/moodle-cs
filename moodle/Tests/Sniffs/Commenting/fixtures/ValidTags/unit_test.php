<?php

/**
 * @covers \some_class
 */
class some_test extends \advanced_testcase {
    /**
     * @dataProvider provider
     * @covers \some_class::some_method
     * @param string $param1
     * @returns string
     * @void
     */
    public function test_something() {
        $this->assertTrue(true);
    }

    /**
     * Some valid tags, to verify they are ok.
     *
     * @license
     * @throws
     * @deprecated
     * @author
     * @todo
     */
    public function all_valid_tags() {
        echo "yay!";
    }

    /**
     * Some more valid tags, because we are under tests area.
     *
     * @covers
     * @dataProvider
     * @group
     * @runTestsInSeparateProcesses
     */
    public function also_all_valid_tags() {
        echo "reyay!";
    }

    /**
     * Some more valid tags, because we are under tests area.
     */
    public function valid_inline_tags() {
        // @codeCoverageIgnoreStart
        echo "reyay!";
        // @codeCoverageIgnoreEnd
    }

    /**
     * Some invalid tags.
     *
     * @small
     * @zzzing
     * @inheritdoc
     */
    public function all_invalid_tags() {
        echo "yoy!";
    }
}
