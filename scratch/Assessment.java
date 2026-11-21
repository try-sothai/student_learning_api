package com.social7.studentlearnanalytics.model;

public class Assessment {
    private String id;
    private String title;
    private String date;
    private int score;
    private int totalScore;
    private String type; // assignment, quiz, exam

    public Assessment(String id, String title, String date, int score, int totalScore, String type) {
        this.id = id;
        this.title = title;
        this.date = date;
        this.score = score;
        this.totalScore = totalScore;
        this.type = type;
    }

    public String getId() { return id; }
    public String getTitle() { return title; }
    public String getDate() { return date; }
    public int getScore() { return score; }
    public int getTotalScore() { return totalScore; }
    public String getType() { return type; }

    public String getFormattedScore() {
        return score + " / " + totalScore;
    }
}
