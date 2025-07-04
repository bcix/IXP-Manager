

########################################################################################
########################################################################################
#
# Filter known bogon asn
#
# Inspired by: https://bgpfilterguide.nlnog.net/guides/bogon_asns/
#
########################################################################################
########################################################################################


define BOGON_ASNS = [
	0,                      # RFC 7607
	23456,                  # RFC 4893 AS_TRANS
	64496..64511,           # RFC 5398 and documentation/example ASNs
	64512..65534,           # RFC 6996 Private ASNs
	65535,                  # RFC 7300 Last 16 bit ASN
	65536..65551,           # RFC 5398 and documentation/example ASNs
	65552..131071,          # RFC IANA reserved ASNs
	4200000000..4294967294, # RFC 6996 Private ASNs
	4294967295 ];           # RFC 7300 Last 32 bit ASN

function filter_has_bogon_as_path()
int set bogon_asns;
{
    bogon_asns = BOGON_ASNS;
    if (bgp_path ~ bogon_asns) then {
        bgp_large_community.add( IXP_LC_FILTERED_BOGON_ASN );
        return true;
    }

    return false;
}
