<?php

test("RDAP - 404 Authoritative Not Found Returns Available (BUG-H2 Verification)", function() {
    $mockWhois = new MockWhoisClient();
    $mockWhois->mockResponse = "Domain not found."; // WHOIS fails
    
    $mockRdap = new MockRdapClient();
    $mockRdap->mockResponse = ['errorCode' => 404, 'title' => 'Not Found']; // RDAP 404 = not registered
    
    $service = createTestWhoisService($mockWhois, $mockRdap);
    
    $result = $service->lookup('notfound404.app'); // .app is rdapFirst
    
    // Authoritative 404 = domain is available, not a failure
    assertTrue($result['success'], "Authoritative 404 should return success=true");
    assertTrue(!empty($result['available']), "Should be marked as available");
    assertEquals('N/A', $result['registrar'], "Registrar should be N/A for unregistered domain");
    assertTrue(in_array('not found', $result['status']), "Status should contain 'not found'");
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
