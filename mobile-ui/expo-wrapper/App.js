import { useRef, useState } from 'react';
import { StatusBar } from 'expo-status-bar';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';
import { WebView } from 'react-native-webview';

const WEB_APP_URL = process.env.EXPO_PUBLIC_WEB_APP_URL || 'http://10.0.2.2:5173/';

export default function App() {
  const webView = useRef(null);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState('');

  return (
    <View style={styles.container}>
      <StatusBar style="light" backgroundColor="#064e3b" />
      <WebView
        ref={webView}
        source={{ uri: WEB_APP_URL }}
        style={styles.webView}
        originWhitelist={['http://*', 'https://*']}
        javaScriptEnabled
        domStorageEnabled
        sharedCookiesEnabled
        setSupportMultipleWindows={false}
        onLoadStart={() => {
          setLoading(true);
          setLoadError('');
        }}
        onLoadEnd={() => setLoading(false)}
        onError={(event) => {
          setLoading(false);
          setLoadError(event.nativeEvent.description || 'Could not open the mobile logbook.');
        }}
        onHttpError={(event) => {
          setLoading(false);
          setLoadError(`The mobile logbook server returned HTTP ${event.nativeEvent.statusCode}.`);
        }}
      />
      {loading && !loadError && (
        <View style={styles.loading} pointerEvents="none">
          <ActivityIndicator color="#0f5132" size="large" />
          <Text style={styles.loadingText}>Opening CEIT Digital Logbook…</Text>
        </View>
      )}
      {loadError ? (
        <View style={styles.errorPanel}>
          <Text style={styles.title}>Can’t reach the mobile logbook</Text>
          <Text style={styles.message}>{loadError}</Text>
          <Text style={styles.hint}>
            Start the Vite server on this computer, then check that the emulator can reach {WEB_APP_URL}
          </Text>
          <Pressable style={styles.button} onPress={() => webView.current?.reload()}>
            <Text style={styles.buttonText}>Try again</Text>
          </Pressable>
        </View>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: '#ecfdf5',
  },
  webView: {
    flex: 1,
    backgroundColor: '#ecfdf5',
  },
  loading: {
    ...StyleSheet.absoluteFillObject,
    alignItems: 'center',
    justifyContent: 'center',
    gap: 12,
    backgroundColor: '#ecfdf5',
  },
  loadingText: {
    color: '#064e3b',
    fontSize: 15,
    fontWeight: '600',
  },
  errorPanel: {
    ...StyleSheet.absoluteFillObject,
    alignItems: 'center',
    justifyContent: 'center',
    padding: 28,
    backgroundColor: '#ecfdf5',
  },
  title: {
    color: '#064e3b',
    fontSize: 22,
    fontWeight: '700',
    textAlign: 'center',
  },
  message: {
    marginTop: 12,
    color: '#7f1d1d',
    fontSize: 14,
    textAlign: 'center',
  },
  hint: {
    marginTop: 12,
    color: '#35614c',
    fontSize: 13,
    lineHeight: 19,
    textAlign: 'center',
  },
  button: {
    marginTop: 22,
    borderRadius: 12,
    backgroundColor: '#0f5132',
    paddingHorizontal: 22,
    paddingVertical: 13,
  },
  buttonText: {
    color: '#fff',
    fontSize: 15,
    fontWeight: '700',
  },
});
