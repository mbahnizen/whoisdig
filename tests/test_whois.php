<?php

test("WhoisService - Successful Lookup & Cache Hit Validation", function() {
    $mockWhois = new MockWhoisClient();
    $mockWhois->mockResponse = "Domain Name: example.com\r\nRegistrar: Mock Registrar\r\nCreation Date: 2020-01-01T00:00:00Z\r\nRegistry Expiry Date: 2025-01-01T00:00:00Z\r\n";
    
    $mockRdap = new MockRdapClient(); // Shouldn't be called if WHOIS succeeds

    $service = createTestWhoisService($mockWhois, $mockRdap);
    
    // First lookup (miss): should invoke WHOIS mock
    $result = $service->lookup('example.com');
    
    assertTrue($result['success']);
    assertEquals('Mock Registrar', $result['registrar']);
    assertEquals(1, $mockWhois->callCount, "WHOIS should be called once for initial lookup");
    
    // Second lookup (hit): should come from cache
    $result2 = $service->lookup('example.com');
    assertTrue($result2['success']);
    assertEquals(1, $mockWhois->callCount, "WHOIS should NOT be called again — cache hit");
});

test("WhoisService - Empty WHOIS Fallback to RDAP", function() {
    $mockWhois = new MockWhoisClient();
    $mockWhois->mockResponse = ""; // Empty WHOIS — triggers fallback
    
    $mockRdap = new MockRdapClient();
    $mockRdap->mockResponse = [
        'events' => [
            ['eventAction' => 'registration', 'eventDate' => '2022-06-15T00:00:00Z'],
            ['eventAction' => 'expiration', 'eventDate' => '2025-06-15T00:00:00Z']
        ],
        'entities' => [
            ['roles' => ['registrar'], 'vcardArray' => [ 'vcard', [ ['fn', clone (object)[], 'text', 'RDAP Fallback Registrar'] ] ] ]
        ]
    ];
    
    $service = createTestWhoisService($mockWhois, $mockRdap);
    
    $result = $service->lookup('rdapfallback.id');
    
    assertTrue($result['success']);
    assertEquals('RDAP Fallback Registrar', $result['registrar']);
    assertTrue($mockRdap->callCount > 0, "RDAP should have been called as fallback");
});

test("WhoisService - Invalid Domain (All Failed)", function() {
    $mockWhois = new MockWhoisClient();
    $mockWhois->mockResponse = "No match for domain.";
    
    $mockRdap = new MockRdapClient();
    $mockRdap->mockResponse = ['errorCode' => 404, 'title' => 'Not Found'];
    
    $service = createTestWhoisService($mockWhois, $mockRdap);
    
    $result = $service->lookup('thisisinvalidxyz123.com');
    
    // .com goes through WHOIS first (empty data) then RDAP fallback returns authoritative 404
    // This should be treated as "domain available", not a failure
    assertTrue($result['success']);
    assertTrue(!empty($result['available']), "Should be marked as available when both WHOIS and RDAP confirm not found");
    assertEquals('N/A', $result['registrar']);
});
