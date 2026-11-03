// A container with an ordinary internet route is never an accepted runtime.
// Host deployment verification additionally requires an internal Docker bridge
// in "isolated" gateway mode, with only this runtime and the egress proxy.
export function hasDirectDefaultRoute(ipv4, ipv6) {
  const v4 = ipv4.trim().split('\n').slice(1).some(line => {
    const fields = line.trim().split(/\s+/);
    return fields[0] !== 'lo' && fields[1] === '00000000' && (parseInt(fields[3], 16) & 1);
  });
  const v6 = ipv6.trim().split('\n').some(line => {
    const fields = line.trim().split(/\s+/);
    return fields.length >= 10 && fields[9] !== 'lo' && /^0{32}$/.test(fields[0]) && fields[1] === '00'
      && (parseInt(fields[8], 16) & 1) && ! (parseInt(fields[8], 16) & 0x200);
  });
  return Boolean(v4 || v6);
}
