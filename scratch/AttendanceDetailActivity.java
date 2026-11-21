package com.social7.studentlearnanalytics.student.attendance;

import android.content.Intent;
import android.graphics.Color;
import android.os.Bundle;
import android.util.Log;
import android.widget.Toast;

import androidx.appcompat.app.AppCompatActivity;

import com.github.mikephil.charting.charts.BarChart;
import com.github.mikephil.charting.charts.PieChart;
import com.github.mikephil.charting.components.XAxis;
import com.github.mikephil.charting.components.YAxis;
import com.github.mikephil.charting.data.BarData;
import com.github.mikephil.charting.data.BarDataSet;
import com.github.mikephil.charting.data.BarEntry;
import com.github.mikephil.charting.data.PieData;
import com.github.mikephil.charting.data.PieDataSet;
import com.github.mikephil.charting.data.PieEntry;
import com.github.mikephil.charting.formatter.IndexAxisValueFormatter;
import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;
import com.social7.studentlearnanalytics.R;
import com.social7.studentlearnanalytics.network.ApiClient;
import com.social7.studentlearnanalytics.student.StudentMainActivity;

import java.text.SimpleDateFormat;
import java.util.ArrayList;
import java.util.Date;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import java.util.TreeMap;

import retrofit2.Call;
import retrofit2.Callback;
import retrofit2.Response;

public class AttendanceDetailActivity extends AppCompatActivity {

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_attendance_detail);

        findViewById(R.id.btnBack).setOnClickListener(v -> finish());

        // Setup Charts with empty data initially
        setupDonutChart(0, 0, "Loading...");
        setupMonthlyBarChart(new ArrayList<>(), new ArrayList<>());

        // Fetch Real API Data
        fetchAttendanceData();

        // Bottom Navigation Listeners
        findViewById(R.id.nav_home).setOnClickListener(v -> openTab(R.id.nav_home));
        findViewById(R.id.nav_analytics).setOnClickListener(v -> openTab(R.id.nav_analytics));
        findViewById(R.id.nav_prediction).setOnClickListener(v -> openTab(R.id.nav_prediction));
        findViewById(R.id.nav_notifications).setOnClickListener(v -> openTab(R.id.nav_notifications));
        findViewById(R.id.nav_profile).setOnClickListener(v -> openTab(R.id.nav_profile));
    }

    private void fetchAttendanceData() {
        ApiClient.getApiService(this).getStudentAttendance().enqueue(new Callback<JsonObject>() {
            @Override
            public void onResponse(Call<JsonObject> call, Response<JsonObject> response) {
                if (response.isSuccessful() && response.body() != null) {
                    try {
                        JsonObject data = response.body().getAsJsonObject("data");
                        JsonObject summary = data.getAsJsonObject("summary");

                        float present = summary.get("present").getAsFloat();
                        float absent = summary.get("absent").getAsFloat();
                        String percentage = summary.get("percentage").getAsString() + "%";

                        setupDonutChart(present, absent, percentage);

                        JsonArray details = data.getAsJsonArray("details");
                        processMonthlyData(details);

                    } catch (Exception e) {
                        Log.e("AttendanceDetail", "Error parsing attendance data", e);
                        Toast.makeText(AttendanceDetailActivity.this, "Error parsing data", Toast.LENGTH_SHORT).show();
                    }
                } else if (response.code() == 401) {
                    Toast.makeText(AttendanceDetailActivity.this, "Session expired, please login again", Toast.LENGTH_LONG).show();
                } else {
                    Toast.makeText(AttendanceDetailActivity.this, "Failed to load attendance", Toast.LENGTH_SHORT).show();
                }
            }

            @Override
            public void onFailure(Call<JsonObject> call, Throwable t) {
                Log.e("AttendanceDetail", "API failure", t);
                Toast.makeText(AttendanceDetailActivity.this, "Network error", Toast.LENGTH_SHORT).show();
            }
        });
    }

    private void processMonthlyData(JsonArray details) {
        Map<String, float[]> monthlyStats = new TreeMap<>();
        SimpleDateFormat sdfIn = new SimpleDateFormat("yyyy-MM-dd", Locale.US);
        SimpleDateFormat sdfOut = new SimpleDateFormat("MMM", Locale.US);

        for (JsonElement element : details) {
            JsonObject detail = element.getAsJsonObject();
            String status = detail.get("status").getAsString();
            
            if (detail.has("attendance") && !detail.get("attendance").isJsonNull()) {
                JsonObject attendance = detail.getAsJsonObject("attendance");
                if (attendance.has("attendance_date") && !attendance.get("attendance_date").isJsonNull()) {
                    String dateStr = attendance.get("attendance_date").getAsString();
                    try {
                        Date date = sdfIn.parse(dateStr);
                        if (date != null) {
                            String sortKey = new SimpleDateFormat("yyyy-MM", Locale.US).format(date);
                            float[] stats = monthlyStats.getOrDefault(sortKey, new float[]{0f, 0f});
                            if (stats != null) {
                                if ("present".equalsIgnoreCase(status) || "late".equalsIgnoreCase(status)) {
                                    stats[0] += 1f;
                                } else {
                                    stats[1] += 1f;
                                }
                                monthlyStats.put(sortKey, stats);
                            }
                        }
                    } catch (Exception e) {
                        Log.e("AttendanceDetail", "Date parse error", e);
                    }
                }
            }
        }

        List<BarEntry> barEntries = new ArrayList<>();
        List<String> labels = new ArrayList<>();
        int i = 0;
        SimpleDateFormat keyFormat = new SimpleDateFormat("yyyy-MM", Locale.US);
        for (Map.Entry<String, float[]> entry : monthlyStats.entrySet()) {
            try {
                Date d = keyFormat.parse(entry.getKey());
                if(d != null) {
                    labels.add(sdfOut.format(d));
                } else {
                    labels.add(entry.getKey());
                }
            } catch (Exception e) {
                labels.add(entry.getKey());
            }
            barEntries.add(new BarEntry(i, entry.getValue()));
            i++;
        }

        setupMonthlyBarChart(barEntries, labels);
    }

    private void setupDonutChart(float present, float absent, String centerText) {
        PieChart donutChart = findViewById(R.id.donutChart);
        if (donutChart == null) return;

        List<PieEntry> entries = new ArrayList<>();
        if (present == 0 && absent == 0) {
            entries.add(new PieEntry(1f, "No Data"));
        } else {
            entries.add(new PieEntry(present, "Present"));
            entries.add(new PieEntry(absent, "Absent"));
        }

        PieDataSet dataSet = new PieDataSet(entries, "");
        if (present == 0 && absent == 0) {
            dataSet.setColors(Color.parseColor("#E0E0E0"));
        } else {
            dataSet.setColors(Color.parseColor("#16B77A"), Color.parseColor("#FF4D67"));
        }
        dataSet.setDrawValues(false);

        PieData data = new PieData(dataSet);
        donutChart.setData(data);
        donutChart.setDrawHoleEnabled(true);
        donutChart.setHoleRadius(72f);
        donutChart.setTransparentCircleRadius(76f);
        donutChart.setHoleColor(Color.TRANSPARENT);
        donutChart.setCenterText(centerText);
        donutChart.setCenterTextSize(22f);
        donutChart.setCenterTextColor(Color.parseColor("#17144A"));
        donutChart.getDescription().setEnabled(false);
        donutChart.getLegend().setEnabled(false);
        donutChart.animateY(800);
        donutChart.invalidate();
    }

    private void setupMonthlyBarChart(List<BarEntry> barEntries, List<String> labels) {
        BarChart barChart = findViewById(R.id.barChartMonthly);
        if (barChart == null) return;

        if (barEntries.isEmpty()) {
            barChart.clear();
            return;
        }

        BarDataSet barDataSet = new BarDataSet(barEntries, "");
        barDataSet.setColors(Color.parseColor("#16B77A"), Color.parseColor("#D9D3F5"));
        barDataSet.setStackLabels(new String[]{"Present", "Absent"});
        barDataSet.setDrawValues(false);

        BarData barData = new BarData(barDataSet);
        barData.setBarWidth(0.45f);
        barChart.setData(barData);

        XAxis xAxis = barChart.getXAxis();
        xAxis.setPosition(XAxis.XAxisPosition.BOTTOM);
        xAxis.setDrawGridLines(false);
        xAxis.setAxisLineColor(Color.TRANSPARENT);
        xAxis.setTextColor(Color.parseColor("#68658A"));
        xAxis.setTextSize(11f);
        xAxis.setValueFormatter(new IndexAxisValueFormatter(labels));
        xAxis.setLabelCount(labels.size());

        YAxis leftAxis = barChart.getAxisLeft();
        leftAxis.setDrawGridLines(true);
        leftAxis.setGridColor(Color.parseColor("#D9D3F5"));
        leftAxis.setAxisLineColor(Color.TRANSPARENT);
        leftAxis.setTextColor(Color.parseColor("#68658A"));
        leftAxis.setTextSize(10f);
        leftAxis.setAxisMinimum(0f);
        
        float maxVal = 10f;
        for (BarEntry entry : barEntries) {
            float sum = entry.getYVals()[0] + entry.getYVals()[1];
            if (sum > maxVal) maxVal = sum;
        }
        leftAxis.setAxisMaximum(maxVal + 2f);

        barChart.getAxisRight().setEnabled(false);
        barChart.getDescription().setEnabled(false);
        barChart.getLegend().setEnabled(false);
        barChart.setTouchEnabled(false);
        barChart.animateY(1000);
        barChart.invalidate();
    }

    private void openTab(int tabId) {
        Intent intent = new Intent(this, StudentMainActivity.class);
        intent.putExtra("select_tab", tabId);
        intent.addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP | Intent.FLAG_ACTIVITY_SINGLE_TOP);
        startActivity(intent);
        finish();
    }
}
