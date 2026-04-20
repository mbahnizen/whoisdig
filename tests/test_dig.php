<?php

use WhoisDig\Resolvers\DigChecker;

test("DigChecker - IPv6 PTR Guard (BUG-H4)", function() {
    $checker = new DigChecker();
    $result = $checker->lookup('2001:4860:4860::8888', 'PTR');
    
    assertFalse($result['success']);
    assertEquals('IPv6 PTR lookup belum didukung saat ini.', $result['error']);
    // Ensure it did not attempt a garbage query
});

// Since dns_get_record cannot be easily mocked in pure PHP without wrappers,
// we will optionally test live DNS resolution if the --live flag is passed.

global $isLive;
if ($isLive) {
    test("DigChecker - Live A Record", function() {
        $checker = new DigChecker();
        $result = $checker->lookup('google.com', 'A');
        assertTrue($result['success']);
        assertTrue(count($result['records']) > 0);
        assertEquals('A', $result['records'][0]['type']);
    });

    test("DigChecker - Live MX Record", function() {
        $checker = new DigChecker();
        $result = $checker->lookup('google.com', 'MX');
        assertTrue($result['success']);
        assertTrue(count($result['records']) > 0);
        assertEquals('MX', $result['records'][0]['type']);
    });
}
