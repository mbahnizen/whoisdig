<?php

test("RDAP - 404 Response Should NOT Pollute Raw Output (BUG-H2 Verification)", function() {
    $mockWhois = new MockWhoisClient();
    $mockWhois->mockResponse = "Domain not found."; // WHOIS fails
    
    $mockRdap = new MockRdapClient();
    $mockRdap->mockResponse = ['errorCode' => 404, 'title' => 'Not Found']; // RDAP fails with 404
    
    $service = createTestWhoisService($mockWhois, $mockRdap);
    
    $result = $service->lookup('notfound404.app'); // .app is rdapFirst
    
    // Success is true because network succeeded, but data should be empty
    assertTrue($result['success']);
    
    // BUG-H2 check: the raw output should NOT contain the RDAP error JSON
    if (isset($result['raw'])) {
        $rawDecoded = base64_decode($result['raw']);
        assertFalse(strpos($rawDecoded, 'errorCode":404'), "Raw output must not contain RDAP 404 JSON");
    }
});

test("RDAP - Preferred TLD Routing", function() {
    $mockWhois = new MockWhoisClient();
    
    $mockRdap = new MockRdapClient();
    $mockRdap->mockResponse = [
        'events' => [
            ['eventAction' => 'registration', 'eventDate' => '2020-01-01T00:00:00Z']
        ],
        'entities' => [
            ['roles' => ['registrar'], 'vcardArray' => [ 'vcard', [ ['fn', clone (object)[], 'text', 'App Registrar'] ] ] ]
        ]
    ];
    
    $service = createTestWhoisService($mockWhois, $mockRdap);
    
    // .dev is a known RDAP-first TLD
    $result = $service->lookup('test.dev');
    
    assertTrue($result['success']);
    assertEquals('App Registrar', $result['registrar']);
    
    // Verify routing priority
    assertEquals(1, $mockRdap->callCount, "RDAP should be called first");
    assertEquals(0, $mockWhois->callCount, "WHOIS should NOT be called if RDAP succeeds on preferred TLD");
});
