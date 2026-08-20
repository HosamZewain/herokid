import{QueryClient,QueryClientProvider}from'@tanstack/react-query';
import * as Notifications from'expo-notifications';
import{router,Stack}from'expo-router';
import{StatusBar}from'expo-status-bar';
import{useEffect,useState}from'react';
import{flushAnalytics,track}from'@/src/analytics';
import{AuthProvider}from'@/src/auth/AuthProvider';
import{I18nProvider}from'@/src/i18n';

Notifications.setNotificationHandler({handleNotification:async()=>({shouldShowBanner:true,shouldShowList:true,shouldPlaySound:true,shouldSetBadge:false})});
export default function RootLayout(){const[client]=useState(()=>new QueryClient({defaultOptions:{queries:{staleTime:60000,retry:2}}}));useEffect(()=>{void track('app_opened');void flushAnalytics();const subscription=Notifications.addNotificationResponseReceivedListener(response=>{const orderId=response.notification.request.content.data?.order_id;if(typeof orderId==='number'||typeof orderId==='string')router.push(`/order/${orderId}`)});return()=>subscription.remove()},[]);return <QueryClientProvider client={client}><I18nProvider><AuthProvider><StatusBar style="dark"/><Stack screenOptions={{headerShown:false}}/></AuthProvider></I18nProvider></QueryClientProvider>}
