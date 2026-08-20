import {getDeviceValue,setDeviceValue} from'@/src/storage/deviceStorage';
import{idempotencyKey}from'@/src/api/idempotency';
const INSTALLATION_KEY='herokid.device.installation';
export async function getInstallationId():Promise<string>{let value=await getDeviceValue(INSTALLATION_KEY);if(!value){value=idempotencyKey();await setDeviceValue(INSTALLATION_KEY,value)}return value}
