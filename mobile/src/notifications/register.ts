import Constants from'expo-constants';
import * as Device from'expo-device';
import * as Notifications from'expo-notifications';
import{Platform}from'react-native';
import{api}from'@/src/api/client';
import type{DeviceInstallation}from'@/src/api/types';
import{getInstallationId}from'@/src/device/installation';

export async function registerForPush(locale:'ar'|'en',marketing=false):Promise<DeviceInstallation>{
 const message=(ar:string,en:string)=>locale==='ar'?ar:en;
 if(!Device.isDevice)throw new Error(message('تتطلب الإشعارات جهازاً فعلياً.','Push notifications require a physical device.'));
 const current=await Notifications.getPermissionsAsync();
 const permission=current.status==='granted'?current:await Notifications.requestPermissionsAsync();
 if(permission.status!=='granted')throw new Error(message('لم يتم منح إذن الإشعارات.','Notification permission was not granted.'));
 if(Platform.OS==='android')await Notifications.setNotificationChannelAsync('orders',{name:message('طلبات HeroKid','HeroKid orders'),importance:Notifications.AndroidImportance.HIGH,sound:'default'});
 const projectId=Constants.expoConfig?.extra?.eas?.projectId;
 if(!projectId||projectId==='SET_WITH_EAS_INIT')throw new Error(message('يجب إعداد مشروع الإشعارات قبل التسجيل.','The push-notification project must be configured before registration.'));
 const pushToken=(await Notifications.getExpoPushTokenAsync({projectId})).data;
 const installationId=await getInstallationId();
 return(await api<{data:DeviceInstallation}>('/devices',{method:'POST',body:JSON.stringify({installation_id:installationId,platform:Platform.OS,app_version:Constants.expoConfig?.version,device_name:Device.deviceName,locale,timezone:Intl.DateTimeFormat().resolvedOptions().timeZone,push_token:pushToken,operational_notifications:true,marketing_notifications:marketing})})).data;
}
