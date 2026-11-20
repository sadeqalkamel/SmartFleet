import 'dart:async';
import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:geolocator/geolocator.dart';
import 'package:http/http.dart' as http;
import 'package:url_launcher/url_launcher.dart';

const apiBase = String.fromEnvironment(
  'API_BASE_URL',
  defaultValue: 'http://10.0.2.2:8000',
);

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(const SmartFleetApp());
}

class ApiException implements Exception {
  final String message;
  ApiException(this.message);
  @override
  String toString() => message;
}

class Api {
  static const storage = FlutterSecureStorage();
  static const key = 'smartfleet_driver_token';
  String? token;

  Future<void> restore() async => token = await storage.read(key: key);
  Future<void> clear() async {
    token = null;
    await storage.delete(key: key);
  }

  Future<dynamic> request(
    String method,
    String path, {
    Map<String, dynamic>? body,
  }) async {
    final uri = Uri.parse('$apiBase$path');
    http.Response result;
    final headers = <String, String>{
      'Accept': 'application/json',
      'Content-Type': 'application/json',
    };
    if (token != null) headers['Authorization'] = 'Bearer $token';
    try {
      final encoded = body == null ? null : jsonEncode(body);
      switch (method) {
        case 'POST':
          result = await http
              .post(uri, headers: headers, body: encoded)
              .timeout(const Duration(seconds: 30));
          break;
        default:
          result = await http
              .get(uri, headers: headers)
              .timeout(const Duration(seconds: 30));
      }
    } catch (error) {
      throw ApiException('تعذر الاتصال بالخادم $apiBase\n$error');
    }
    dynamic data;
    try {
      data = jsonDecode(result.body);
    } catch (_) {
      throw ApiException('استجابة غير صحيحة من الخادم (${result.statusCode})');
    }
    if (result.statusCode >= 400) {
      final errors = data is Map ? data['errors'] : null;
      final detail = errors is Map
          ? errors.values.map((e) => e is List ? e.join('، ') : '$e').join('، ')
          : null;
      throw ApiException(
        detail?.isNotEmpty == true
            ? detail!
            : (data is Map
                  ? '${data['message'] ?? 'خطأ في الخادم'}'
                  : 'HTTP ${result.statusCode}'),
      );
    }
    return data;
  }

  Future<void> login(String email, String password) async {
    final data = await request(
      'POST',
      '/api/login',
      body: {'email': email, 'password': password},
    );
    if (data is! Map || data['token'] is! String) {
      throw ApiException(
        'لم يرجع الخادم رمز تسجيل دخول. تحقق من تنسيق /api/login',
      );
    }
    token = data['token'] as String;
    try {
      final me = await request('GET', '/api/me');
      final user = me is Map && me['user'] is Map ? me['user'] : me;
      if (user is! Map || user['role'] != 'driver')
        throw ApiException('هذا التطبيق مخصص لحسابات السائقين فقط');
      await storage.write(key: key, value: token);
    } catch (_) {
      token = null;
      rethrow;
    }
  }

  Future<bool> checkDriver() async {
    if (token == null) return false;
    try {
      final me = await request('GET', '/api/me');
      final user = me is Map && me['user'] is Map ? me['user'] : me;
      if (user is Map && user['role'] == 'driver') return true;
    } catch (_) {}
    await clear();
    return false;
  }
}

final api = Api();

class SmartFleetApp extends StatefulWidget {
  const SmartFleetApp({super.key});
  @override
  State<SmartFleetApp> createState() => _SmartFleetAppState();
}

class _SmartFleetAppState extends State<SmartFleetApp> {
  bool ready = false;
  bool authorized = false;
  @override
  void initState() {
    super.initState();
    _restore();
  }

  Future<void> _restore() async {
    await api.restore();
    final valid = await api.checkDriver();
    if (mounted)
      setState(() {
        authorized = valid;
        ready = true;
      });
  }

  @override
  Widget build(BuildContext context) => MaterialApp(
    debugShowCheckedModeBanner: false,
    title: 'SmartFleet Driver',
    theme: ThemeData(
      useMaterial3: true,
      colorScheme: ColorScheme.fromSeed(seedColor: const Color(0xff0e7490)),
    ),
    builder: (context, child) =>
        Directionality(textDirection: TextDirection.rtl, child: child!),
    home: !ready
        ? const Scaffold(body: Center(child: CircularProgressIndicator()))
        : authorized
        ? DriverHome(onLogout: () => setState(() => authorized = false))
        : LoginPage(onSuccess: () => setState(() => authorized = true)),
  );
}

class LoginPage extends StatefulWidget {
  final VoidCallback onSuccess;
  const LoginPage({required this.onSuccess, super.key});
  @override
  State<LoginPage> createState() => _LoginPageState();
}

class _LoginPageState extends State<LoginPage> {
  final email = TextEditingController();
  final password = TextEditingController();
  bool loading = false;
  String? error;
  Future<void> submit() async {
    setState(() {
      loading = true;
      error = null;
    });
    try {
      await api.login(email.text.trim(), password.text);
      if (mounted) widget.onSuccess();
    } catch (e) {
      if (mounted) setState(() => error = '$e');
    } finally {
      if (mounted) setState(() => loading = false);
    }
  }

  @override
  void dispose() {
    email.dispose();
    password.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('SmartFleet | دخول السائق')),
    body: Center(
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 450),
        child: ListView(
          shrinkWrap: true,
          padding: const EdgeInsets.all(24),
          children: [
            const Icon(
              Icons.local_shipping,
              size: 72,
              color: Color(0xff0e7490),
            ),
            const SizedBox(height: 20),
            TextField(
              controller: email,
              keyboardType: TextInputType.emailAddress,
              decoration: const InputDecoration(
                labelText: 'البريد الإلكتروني',
                border: OutlineInputBorder(),
              ),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: password,
              obscureText: true,
              decoration: const InputDecoration(
                labelText: 'كلمة المرور',
                border: OutlineInputBorder(),
              ),
            ),
            const SizedBox(height: 16),
            if (error != null)
              Text(error!, style: const TextStyle(color: Colors.red)),
            FilledButton(
              onPressed: loading ? null : submit,
              child: Text(loading ? 'جارٍ الدخول...' : 'تسجيل الدخول'),
            ),
            const SizedBox(height: 10),
            Text(
              'الخادم: $apiBase',
              style: Theme.of(context).textTheme.bodySmall,
            ),
          ],
        ),
      ),
    ),
  );
}

class DriverHome extends StatefulWidget {
  final VoidCallback onLogout;
  const DriverHome({required this.onLogout, super.key});
  @override
  State<DriverHome> createState() => _DriverHomeState();
}

class _DriverHomeState extends State<DriverHome> with WidgetsBindingObserver {
  List<dynamic> routes = [];
  bool loading = true;
  String? error;
  StreamSubscription<Position>? gpsSubscription;
  DateTime? lastGpsSent;
  bool gpsEnabled = false;
  bool gpsSending = false;
  String gpsStatus = 'الموقع متوقف';

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    refresh();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    // Foreground-only tracking: stop if app is not active.
    if (state != AppLifecycleState.resumed) stopGps();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    gpsSubscription?.cancel();
    super.dispose();
  }

  Future<void> refresh() async {
    setState(() {
      loading = true;
      error = null;
    });
    try {
      final data = await api.request('GET', '/api/driver/routes');
      if (!mounted) return;
      setState(() {
        routes = data is Map && data['routes'] is List
            ? data['routes'] as List
            : [];
      });
    } catch (e) {
      if (mounted) setState(() => error = '$e');
    } finally {
      if (mounted) setState(() => loading = false);
    }
  }

  Future<void> startGps() async {
    try {
      if (!await Geolocator.isLocationServiceEnabled())
        throw ApiException('فعّل خدمات الموقع في الجهاز');
      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied)
        permission = await Geolocator.requestPermission();
      if (permission == LocationPermission.denied ||
          permission == LocationPermission.deniedForever)
        throw ApiException('يلزم السماح بالوصول للموقع أثناء استخدام التطبيق');
      await gpsSubscription?.cancel();
      gpsEnabled = true;
      setState(() => gpsStatus = 'جارٍ تحديد الموقع...');
      gpsSubscription =
          Geolocator.getPositionStream(
            locationSettings: const LocationSettings(
              accuracy: LocationAccuracy.high,
              distanceFilter: 15,
            ),
          ).listen(
            (position) async {
              if (!gpsEnabled || gpsSending) return;
              if (lastGpsSent != null &&
                  DateTime.now().difference(lastGpsSent!).inSeconds < 10)
                return;
              gpsSending = true;
              try {
                await api.request(
                  'POST',
                  '/api/driver/location',
                  body: {
                    'latitude': position.latitude,
                    'longitude': position.longitude,
                    'accuracy_meters': position.accuracy,
                    'speed_mps': position.speed < 0 ? 0 : position.speed,
                    'heading_degrees': position.heading < 0
                        ? 0
                        : position.heading,
                  },
                );
                lastGpsSent = DateTime.now();
                if (mounted)
                  setState(
                    () => gpsStatus =
                        'تم إرسال الموقع: ${position.latitude.toStringAsFixed(5)}, ${position.longitude.toStringAsFixed(5)}',
                  );
              } catch (e) {
                if (mounted) setState(() => gpsStatus = 'فشل إرسال الموقع: $e');
              } finally {
                gpsSending = false;
              }
            },
            onError: (Object e) {
              if (mounted) setState(() => gpsStatus = 'خطأ GPS: $e');
            },
          );
      if (mounted) setState(() {});
    } catch (e) {
      gpsEnabled = false;
      if (mounted) setState(() => gpsStatus = '$e');
    }
  }

  void stopGps() {
    gpsEnabled = false;
    gpsSubscription?.cancel();
    gpsSubscription = null;
    if (mounted)
      setState(() => gpsStatus = 'الموقع متوقف (يتطلب بقاء التطبيق مفتوحًا)');
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(
      title: const Text('رحلات السائق'),
      actions: [
        IconButton(onPressed: refresh, icon: const Icon(Icons.refresh)),
        IconButton(
          tooltip: 'تسجيل خروج',
          onPressed: () async {
            stopGps();
            await api.clear();
            widget.onLogout();
          },
          icon: const Icon(Icons.logout),
        ),
      ],
    ),
    body: RefreshIndicator(
      onRefresh: refresh,
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(
                    'تتبع الموقع أثناء فتح التطبيق',
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                  Text(gpsStatus),
                  const SizedBox(height: 8),
                  OutlinedButton.icon(
                    onPressed: gpsEnabled ? stopGps : startGps,
                    icon: Icon(
                      gpsEnabled ? Icons.location_off : Icons.my_location,
                    ),
                    label: Text(gpsEnabled ? 'إيقاف GPS' : 'بدء إرسال GPS'),
                  ),
                ],
              ),
            ),
          ),
          if (error != null)
            Padding(
              padding: const EdgeInsets.all(8),
              child: Text(error!, style: const TextStyle(color: Colors.red)),
            ),
          if (loading) const Center(child: CircularProgressIndicator()),
          if (!loading && routes.isEmpty)
            const Padding(
              padding: EdgeInsets.all(25),
              child: Center(child: Text('لا توجد رحلات مسندة إليك')),
            ),
          for (final item in routes)
            if (item is Map)
              Card(
                child: ListTile(
                  leading: const Icon(Icons.route),
                  title: Text('${item['route_number'] ?? 'رحلة'}'),
                  subtitle: Text(
                    'التاريخ: ${item['route_date'] ?? '-'} | الحالة: ${statusText(item['status'])}',
                  ),
                  trailing: const Icon(Icons.chevron_left),
                  onTap: () async {
                    final routeId = item['id'];
                    await Navigator.of(context).push(
                      MaterialPageRoute(
                        builder: (_) => RouteDetail(routeId: routeId),
                      ),
                    );
                    if (mounted) refresh();
                  },
                ),
              ),
        ],
      ),
    ),
  );
}

String statusText(dynamic status) => switch ('$status') {
  'planned' => 'مخططة',
  'in_progress' => 'قيد التنفيذ',
  'completed' => 'مكتملة',
  'pending' => 'بانتظار التسليم',
  'delivered' => 'تم التسليم',
  _ => '$status',
};

class RouteDetail extends StatefulWidget {
  final dynamic routeId;
  const RouteDetail({required this.routeId, super.key});
  @override
  State<RouteDetail> createState() => _RouteDetailState();
}

class _RouteDetailState extends State<RouteDetail> {
  Map<String, dynamic>? route;
  bool loading = true;
  bool acting = false;
  String? error;
  @override
  void initState() {
    super.initState();
    refresh();
  }

  Future<void> refresh() async {
    setState(() {
      loading = true;
      error = null;
    });
    try {
      final data = await api.request(
        'GET',
        '/api/driver/routes/${widget.routeId}',
      );
      if (mounted)
        setState(() => route = Map<String, dynamic>.from(data['route'] as Map));
    } catch (e) {
      if (mounted) setState(() => error = '$e');
    } finally {
      if (mounted) setState(() => loading = false);
    }
  }

  Future<void> act(String path, String question) async {
    final yes = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('تأكيد الإجراء'),
        content: Text(question),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('إلغاء'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('تأكيد'),
          ),
        ],
      ),
    );
    if (yes != true) return;
    setState(() {
      acting = true;
      error = null;
    });
    try {
      final data = await api.request('POST', path);
      if (mounted && data is Map && data['route'] is Map)
        setState(() => route = Map<String, dynamic>.from(data['route'] as Map));
      else
        await refresh();
    } catch (e) {
      if (mounted) setState(() => error = '$e');
    } finally {
      if (mounted) setState(() => acting = false);
    }
  }

  Future<void> navigateTo(dynamic latitude, dynamic longitude) async {
    final lat = double.tryParse('$latitude');
    final lon = double.tryParse('$longitude');
    if (lat == null || lon == null) return;
    // Navigation happens in an external map application, never silently in the background.
    final url = Uri.https('www.google.com', '/maps/dir/', {
      'api': '1',
      'destination': '$lat,$lon',
      'travelmode': 'driving',
    });
    if (!await launchUrl(url, mode: LaunchMode.externalApplication) &&
        mounted) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(const SnackBar(content: Text('تعذر فتح تطبيق الخرائط')));
    }
  }

  @override
  Widget build(BuildContext context) {
    final current = route;
    final stops = current?['stops'] is List
        ? current!['stops'] as List
        : <dynamic>[];
    final state = '${current?['status']}';
    final pending = stops
        .where((s) => s is Map && s['status'] != 'completed')
        .toList();
    final next = pending.isEmpty ? null : pending.first;
    return Scaffold(
      appBar: AppBar(
        title: Text('${current?['route_number'] ?? 'تفاصيل الرحلة'}'),
        actions: [
          IconButton(onPressed: refresh, icon: const Icon(Icons.refresh)),
        ],
      ),
      body: loading
          ? const Center(child: CircularProgressIndicator())
          : ListView(
              padding: const EdgeInsets.all(16),
              children: [
                if (error != null)
                  Text(error!, style: const TextStyle(color: Colors.red)),
                if (current == null) const Text('تعذر تحميل تفاصيل الرحلة'),
                if (current != null) ...[
                  Text(
                    'الحالة: ${statusText(state)}',
                    style: Theme.of(context).textTheme.titleLarge,
                  ),
                  Text(
                    'الطلبات المسلّمة: ${stops.length - pending.length} / ${stops.length}',
                  ),
                  const SizedBox(height: 12),
                  if (state == 'planned')
                    FilledButton.icon(
                      onPressed: acting
                          ? null
                          : () => act(
                              '/api/driver/routes/${widget.routeId}/start',
                              'هل تريد بدء هذه الرحلة؟',
                            ),
                      icon: const Icon(Icons.play_arrow),
                      label: const Text('بدء الرحلة'),
                    ),
                  if (state == 'in_progress' &&
                      pending.isEmpty &&
                      stops.isNotEmpty)
                    FilledButton.icon(
                      onPressed: acting
                          ? null
                          : () => act(
                              '/api/driver/routes/${widget.routeId}/finish',
                              'هل تريد إنهاء الرحلة بعد تسليم كل الطلبات؟',
                            ),
                      icon: const Icon(Icons.flag),
                      label: const Text('إنهاء الرحلة'),
                    ),
                  const SizedBox(height: 12),
                  for (final raw in stops)
                    if (raw is Map)
                      Builder(
                        builder: (context) {
                          final stop = raw;
                          final order = stop['order'] is Map
                              ? stop['order'] as Map
                              : <dynamic, dynamic>{};
                          final isNext =
                              next is Map && next['id'] == stop['id'];
                          final completed = stop['status'] == 'completed';
                          return Card(
                            child: Padding(
                              padding: const EdgeInsets.all(12),
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.stretch,
                                children: [
                                  ListTile(
                                    leading: Icon(
                                      completed
                                          ? Icons.check_circle
                                          : Icons.location_on,
                                      color: completed
                                          ? Colors.green
                                          : Colors.orange,
                                    ),
                                    title: Text(
                                      'المحطة ${stop['sequence']} — ${order['order_number'] ?? ''}',
                                    ),
                                    subtitle: Text(
                                      '${order['customer_name'] ?? ''}\n${order['delivery_address'] ?? ''}\n${statusText(stop['status'])}',
                                    ),
                                  ),
                                  Wrap(
                                    spacing: 8,
                                    children: [
                                      TextButton.icon(
                                        onPressed: () => navigateTo(
                                          order['latitude'],
                                          order['longitude'],
                                        ),
                                        icon: const Icon(Icons.navigation),
                                        label: const Text('فتح الملاحة'),
                                      ),
                                      if (state == 'in_progress' &&
                                          isNext &&
                                          !completed)
                                        FilledButton.icon(
                                          onPressed: acting
                                              ? null
                                              : () => act(
                                                  '/api/driver/routes/${widget.routeId}/stops/${stop['id']}/deliver',
                                                  'تأكيد تسليم الطلب ${order['order_number']}؟ سيُحفظ في قاعدة البيانات.',
                                                ),
                                          icon: const Icon(Icons.done_all),
                                          label: const Text('تأكيد التسليم'),
                                        ),
                                    ],
                                  ),
                                ],
                              ),
                            ),
                          );
                        },
                      ),
                  if (acting) const LinearProgressIndicator(),
                ],
              ],
            ),
    );
  }
}
