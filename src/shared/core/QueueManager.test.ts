import { QueueManager } from './QueueManager';
import { post } from '../utils/ajax';

jest.mock('../utils/ajax', () => ({
  post: jest.fn(),
}));

jest.mock('../utils/toast', () => ({
  showSuccess: jest.fn(),
  showInfo: jest.fn(),
}));

describe('QueueManager', () => {
  test('normalizes the wrapped PHP queue status payload', async () => {
    (post as jest.Mock).mockResolvedValue({
      data: {
        success: true,
        data: {
          status: {
            total_tasks: 12,
            pending: 3,
            processing: 2,
            completed: 6,
            failed: 1,
            is_processing: true,
          },
        },
      },
    });

    const manager = new QueueManager();

    await expect(manager.getQueueStatus()).resolves.toEqual({
      total_jobs: 12,
      pending_jobs: 3,
      processing_jobs: 2,
      completed_jobs: 6,
      failed_jobs: 1,
      queue_size: 3,
      is_processing: true,
      last_processed: '',
    });
  });
});
