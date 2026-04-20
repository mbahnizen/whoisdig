<?php

test("WhoisService - Successful Lookup & Cache Hit Validation", function() {
    $mockWhois = new MockWhoisClient();
    $mockWhois->mockResponse = "Domain Name: GOOGLE.COM\nRegistrar: MarkMonitor Inc.\nCreation Date: 1997-09-15T04:00:00Z\nRegistry Expiry Date: 2028-09-14T04:00:00Z";
    
    $mockRdap = new MockRdapClient(); // Shouldn't be called if WHOIS succeeds
    
    $service = createTestWhoisService($mockWhois, $mockRdap);
    
    // Call 1
    $result1 = $service->lookup('google.com');
    assertTrue($result1['success']);
    assertEquals('MarkMonitor Inc.', $result1['registrar']);
    assertEquals(1, $mockWhois->callCount, "WhoisClient should be called exactly once");
    assertEquals(0, $mockRdap->callCount, "RdapClient should NOT be called");

    // Call 2 (Cache Hit)
    $result2 = $service->lookup('google.com');
    assertTrue($result2['success']);
    assertEquals(1, $mockWhois->callCount, "WhoisClient should NOT be called again (Cache Hit)");
});

test("WhoisService - Empty WHOIS Fallback to RDAP", function() {
    $mockWhois = new MockWhoisClient();
    $mockWhois->mockResponse = "No useful data here."; // Empty/useless WHOIS data
    
    $mockRdap = new MockRdapClient();
    $mockRdap->mockResponse = [
        'events' => [
            ['eventAction' => 'registration', 'eventDate' => '1997-09-15T04:00:00Z']
        ],
        'entities' => [
            ['roles' => ['registrar'], 'vcardArray' => [ 'vcard', [ ['fn', clone (object)[], 'text', 'Mock Registrar'] ] ] ]
        ]
    ];
    
    $service = createTestWhoisService($mockWhois, $mockRdap);
    
    $result = $service->lookup('empty-whois.id');
    
    assertTrue($result['success']);
    assertEquals('Mock Registrar', $result['registrar'], "Should fallback to RDAP data");
    assertEquals(1, $mockWhois->callCount);
    assertEquals(1, $mockRdap->callCount, "RDAP should be called as fallback");
});

test("WhoisService - Invalid Domain (All Failed)", function() {
    $mockWhois = new MockWhoisClient();
    $mockWhois->mockResponse = "No match for domain.";
    
    $mockRdap = new MockRdapClient();
    $mockRdap->mockResponse = ['errorCode' => 404, 'title' => 'Not Found'];
    
    $service = createTestWhoisService($mockWhois, $mockRdap);
    
    $result = $service->lookup('thisisinvalidxyz123.com');
    
    // WhoisService returns success => true if network succeeds, even if domain has no match
    assertTrue($result['success']);
    assertEquals('N/A', $result['registrar']);
    assertEquals("No match for domain.", base64_decode($result['raw']));
});
